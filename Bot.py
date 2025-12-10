import os
import logging
from datetime import datetime
from typing import List, Dict, Optional, Any

import psycopg2
from psycopg2.extras import RealDictCursor
from dotenv import load_dotenv

from telegram import (
    Update,
    ChatMemberUpdated,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    WebAppInfo,  # лишили про запас, стане в нагоді, коли буде HTTPS-домен
)
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    ChatMemberHandler,
    CallbackQueryHandler,
    ContextTypes,
    filters,
)
from urllib.parse import urlencode

# ---------- Логування ----------

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
log = logging.getLogger("testfixed-bot")

# ---------- ENV ----------

load_dotenv()

BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN")
if not BOT_TOKEN:
    raise SystemExit("TELEGRAM_BOT_TOKEN is missing in .env")

WEBAPP_BASE_URL = os.getenv("TELEGRAM_WEBAPP_BASE_URL", "").strip()

# якщо лінк не HTTPS — відрубаємо WebApp і включимо fallback (callback-кнопка)
if WEBAPP_BASE_URL and not WEBAPP_BASE_URL.startswith("https://"):
    log.warning(
        "WEBAPP_BASE_URL is not https, disabling WebApp buttons: %s",
        WEBAPP_BASE_URL,
    )
    WEBAPP_BASE_URL = ""

DB = dict(
    host=os.getenv("DB_HOST", "127.0.0.1"),
    port=int(os.getenv("DB_PORT", "5432")),
    dbname=os.getenv("DB_NAME", "testfixed"),
    user=os.getenv("DB_USER", "postgres"),
    password=os.getenv("DB_PASS", ""),
)


def db():
    """Нове підключення до PostgreSQL."""
    return psycopg2.connect(**DB, cursor_factory=RealDictCursor)


# =====================================================================
#                DB helpers (чати / адміни / прив'язка юзера)
# =====================================================================

def upsert_chat(chat_id: int, title: Optional[str], chat_type: Optional[str]) -> None:
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO chats (chat_id, title, chat_type, updated_at)
            VALUES (%s, %s, %s, NOW())
            ON CONFLICT (chat_id)
            DO UPDATE SET
                title      = COALESCE(EXCLUDED.title, chats.title),
                chat_type  = COALESCE(EXCLUDED.chat_type, chats.chat_type),
                updated_at = NOW()
            """,
            (chat_id, title, chat_type),
        )


def upsert_admin(chat_id: int, tg_user_id: int) -> None:
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO chat_admins (chat_id, admin_tg_user_id)
            VALUES (%s, %s)
            ON CONFLICT (chat_id, admin_tg_user_id) DO NOTHING
            """,
            (chat_id, tg_user_id),
        )


def link_user(site_user_id: int, tg_user_id: int, tg_username: Optional[str]) -> int:
    """Прив'язка users.id до Telegram-користувача."""
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            UPDATE users
               SET tg_user_id   = %s,
                   tg_username  = %s,
                   tg_linked_at = NOW()
             WHERE id = %s
            """,
            (tg_user_id, tg_username, site_user_id),
        )
        return cur.rowcount


# =====================================================================
#                DB helpers (WAF події + clients)
# =====================================================================

def fetch_pending_events(limit: int = 20) -> List[Dict[str, Any]]:
    """
    Беремо події WAF, для яких ще не надсилали Telegram (telegram_sent = FALSE).
    """
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            SELECT
                e.id,
                e.site_id,
                e.label,
                e.score,
                e.url,
                e.ip,
                e.ua,
                e.created_at,
                s.url   AS site_url,
                s.title AS site_title,
                s.telegram_chat_id,
                s.telegram_template,
                s.tg_alert_template
            FROM waf_events e
            JOIN sites s ON s.id = e.site_id
            WHERE e.telegram_sent = FALSE
            ORDER BY e.id ASC
            LIMIT %s
            """,
            (limit,),
        )
        rows = cur.fetchall()
    return rows or []



def mark_event_sent(event_id: int) -> None:
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            UPDATE waf_events
               SET telegram_sent  = TRUE,
                   telegram_error = NULL
             WHERE id = %s
            """,
            (event_id,),
        )


def mark_event_error(event_id: int, error_text: str) -> None:
    short = (error_text or "")[:500]
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            UPDATE waf_events
               SET telegram_sent  = TRUE,
                   telegram_error = %s
             WHERE id = %s
            """,
            (short, event_id),
        )


def fetch_event_with_client(event_id: int) -> Optional[Dict[str, Any]]:
    """
    Беремо подію + останній запис з clients для цієї пари (site_id, ip)
    """
    with db() as conn, conn.cursor() as cur:
        cur.execute(
            """
            SELECT
                e.id           AS event_id,
                e.site_id,
                e.label,
                e.score,
                e.url,
                e.ip,
                e.ua,
                e.created_at,
                s.url          AS site_url,
                s.title        AS site_title,
                c.id           AS client_id,
                c.country,
                c.country_code,
                c.region,
                c.region_name,
                c.city,
                c.zip_code,
                c.latitude,
                c.longitude,
                c.timezone,
                c.currency,
                c.isp,
                c.organization,
                c.is_proxy,
                c.request_time AS client_request_time
            FROM waf_events e
            LEFT JOIN sites   s ON s.id = e.site_id
            LEFT JOIN clients c
                   ON c.ip = e.ip
                  AND c.site_id = e.site_id
            WHERE e.id = %s
            ORDER BY c.request_time DESC NULLS LAST, c.id DESC
            LIMIT 1
            """,
            (event_id,),
        )
        row = cur.fetchone()
    return row


# =====================================================================
#                Шаблон основного алерта
# =====================================================================

DEFAULT_TEMPLATE = (
    "Новий інцидент WAF:\n"
    "Сайт: {site_url}\n"
    "Назва: {site_title}\n"
    "Тип: {label}\n"
    "Рейтинг: {score}\n"
    "URL: {url}\n"
    "IP: {ip}\n"
    "User-Agent: {user_agent}\n"
    "Час: {date} {time}\n"
    "ID події: {id}"
)



def render_telegram_message(row: Dict[str, Any]) -> str:
    # 1) беремо новий шаблон з sites.tg_alert_template,
    #    якщо його немає — старий telegram_template,
    #    якщо й його немає — DEFAULT_TEMPLATE
    template = (
        row.get("tg_alert_template")
        or row.get("telegram_template")
        or DEFAULT_TEMPLATE
    )

    created_at = row.get("created_at")

    date_str = ""
    time_str = ""

    if isinstance(created_at, datetime):
        date_str = created_at.strftime("%Y-%m-%d")
        time_str = created_at.strftime("%H:%M:%S")
    else:
        # якщо раптом прийшов рядок
        try:
            dt = datetime.fromisoformat(str(created_at))
            date_str = dt.strftime("%Y-%m-%d")
            time_str = dt.strftime("%H:%M:%S")
        except Exception:
            pass

    data = {
        "id": row.get("id"),
        "site_id": row.get("site_id"),
        "site_url": row.get("site_url") or "",
        "site_title": row.get("site_title") or "",
        "label": row.get("label") or "",
        "score": row.get("score"),
        "url": row.get("url") or "",
        "ip": row.get("ip") or "",
        "user_agent": row.get("ua") or "",
        "date": date_str,
        "time": time_str,
        # додаткові плейсхолдери з вікна "Текст бота"
        "chat_id": row.get("telegram_chat_id") or "",
        "tg_username": "",  # якщо треба — потім підтягнемо з users
    }

    # підтримуємо формат {{placeholders}} з сайту
    template_fmt = template.replace("{{", "{").replace("}}", "}")

    try:
        return template_fmt.format(**data)
    except Exception as e:
        log.warning(
            "Template format error for event %s: %s (template=%r)",
            row.get("id"), e, template
        )
        # запасний варіант — дефолтний шаблон
        fallback = DEFAULT_TEMPLATE.replace("{{", "{").replace("}}", "}")
        try:
            return fallback.format(**data)
        except Exception:
            # останній резервний варіант
            return f"Новий інцидент WAF на сайті {data['site_url']} (ID події: {data['id']})"



def _fmt_dt(value: Any) -> str:
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    if value is None:
        return "-"
    return str(value)


def _fmt_bool(value: Any) -> str:
    if value is None:
        return "-"
    return "так" if bool(value) else "ні"


def format_client_details(row: Optional[Dict[str, Any]]) -> str:
    """
    Формує текст з ДАНИХ ТАБЛИЦІ clients для кнопки «Подивитись детальніше».
    (у WebApp вже можна показувати browsers/headers/connection_info)
    """
    if not row:
        return "Не знайдено деталей клієнта для цієї події."

    lines: List[str] = []

    # Трохи контексту по інциденту
    lines.append(f"Інцидент #{row.get('event_id')}")
    lines.append(f"IP: {row.get('ip') or '-'}")
    lines.append("")

    lines.append("Дані клієнта (clients):")

    client_id = row.get("client_id")
    if client_id is not None:
        lines.append(f"id: {client_id}")

    country = row.get("country")
    country_code = row.get("country_code")
    if country or country_code:
        if country and country_code:
            lines.append(f"Країна: {country} ({country_code})")
        elif country:
            lines.append(f"Країна: {country}")
        else:
            lines.append(f"Код країни: {country_code}")

    region = row.get("region_name") or row.get("region")
    if region:
        lines.append(f"Регіон: {region}")

    city = row.get("city")
    if city:
        lines.append(f"Місто: {city}")

    zip_code = row.get("zip_code")
    if zip_code:
        lines.append(f"Поштовий індекс: {zip_code}")

    lat = row.get("latitude")
    lon = row.get("longitude")
    if lat is not None and lon is not None:
        lines.append(f"Координати: {lat}, {lon}")

    tz = row.get("timezone")
    if tz:
        lines.append(f"Часовий пояс: {tz}")

    cur = row.get("currency")
    if cur:
        lines.append(f"Валюта: {cur}")

    isp = row.get("isp")
    org = row.get("organization")
    if isp or org:
        if isp and org and isp != org:
            lines.append(f"Провайдер: {isp} ({org})")
        else:
            lines.append(f"Провайдер: {isp or org}")

    is_proxy = row.get("is_proxy")
    if is_proxy is not None:
        lines.append(f"Проксі/VPN: {_fmt_bool(is_proxy)}")

    # ВАЖЛИВО: тут правильне поле client_request_time (як в SELECT)
    crt = row.get("client_request_time")
    if crt:
        lines.append(f"Останній візит IP: {_fmt_dt(crt)}")

    return "\n".join(lines)


# =====================================================================
#                handlers команд / апдейтів
# =====================================================================

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """
    /start <site_user_id> — прив'язка акаунта сайту до Telegram.
    """
    msg = update.effective_message
    user = update.effective_user
    args = context.args or []

    site_user_id: Optional[int] = None
    if args:
        try:
            site_user_id = int(args[0])
        except ValueError:
            site_user_id = None

    if not site_user_id:
        await msg.reply_text(
            "Привіт! Відкрий мене з сайту (кнопка «Прив’язати Telegram»), "
            "щоб завершити прив’язку."
        )
        return

    updated = link_user(site_user_id, user.id, user.username)

    if updated:
        await msg.reply_text(
            f"Готово! Акаунт прив’язано: @{user.username or user.id}"
        )
    else:
        await msg.reply_text(
            "Не вдалося прив’язати акаунт. Перевір, що ти увійшов на сайті."
        )


async def help_cmd(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await update.effective_message.reply_text(
        "Я службовий бот TestFixed.\n"
        "Використовуй /start через кнопку на сайті, щоб прив’язати акаунт.\n"
        "Також я надсилаю сповіщення про інциденти WAF."
    )


async def on_message(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """
    Будь-яке повідомлення з групи/каналу — оновлюємо кеш чатів.
    """
    chat = update.effective_chat
    if chat and chat.type in ("group", "supergroup", "channel"):
        upsert_chat(chat.id, getattr(chat, "title", None), chat.type)


async def on_my_chat_member(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """
    Зміна статусу БОТА в чаті.
    """
    m: ChatMemberUpdated = update.my_chat_member
    chat = m.chat
    new = m.new_chat_member

    upsert_chat(chat.id, getattr(chat, "title", None), chat.type)

    if new.status in ("administrator", "creator"):
        me = await context.bot.get_me()
        upsert_admin(chat.id, me.id)
        log.info("Bot is admin in chat %s (%s)", chat.id, chat.title)


async def on_chat_member(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """
    Зміна статусу ЗВИЧАЙНОГО КОРИСТУВАЧА в чаті.
    """
    cm: ChatMemberUpdated = update.chat_member
    chat = cm.chat
    new = cm.new_chat_member

    upsert_chat(chat.id, getattr(chat, "title", None), chat.type)

    user_id = new.user.id
    if new.status in ("administrator", "creator"):
        upsert_admin(chat.id, user_id)
        log.info("User %s is admin in chat %s (%s)", user_id, chat.id, chat.title)


# =====================================================================
#                callback: кнопка «Подивитись детальніше»
# =====================================================================

async def on_details_callback(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if not query or not query.data:
        return

    data = query.data
    if not data.startswith("details:"):
        # це не наш callback — ігноруємо
        return

    # ACK кнопки (просто прибрати "годинник", БЕЗ тексту)
    await query.answer()

    # витягуємо event_id з callback_data
    try:
        event_id = int(data.split(":", 1)[1])
    except ValueError:
        if query.message:
            await query.message.reply_text("Помилка: некоректний ID події.")
        return

    # тягнемо дані з БД
    row = fetch_event_with_client(event_id)
    text = format_client_details(row) or ""

    if not text.strip():
        text = "Не знайдено деталей для цієї події."

    # на всякий випадок, щоб не влізти в 4096 символів
    if len(text) > 4000:
        text = text[:3990] + "…"

    # шлемо ДЕТАЛІ як звичайне повідомлення в чат
    if query.message:
        await query.message.reply_text(text)

        if row:
            lat = row.get("latitude")
            lon = row.get("longitude")

            if lat is not None and lon is not None:
                try:
                    await query.message.reply_location(
                        latitude=float(lat),
                        longitude=float(lon),
                    )
                except Exception as e:
                    log.error("Failed to send location for event %s: %s", event_id, e)

# =====================================================================
#                job: відправка WAF-подій у Telegram
# =====================================================================

async def poll_waf_events(context: ContextTypes.DEFAULT_TYPE) -> None:
    bot = context.bot

    events = fetch_pending_events(limit=20)
    if not events:
        return

    log.info("Found %s pending WAF events", len(events))

    for ev in events:
        event_id = ev["id"]
        chat_id = ev.get("telegram_chat_id")

        if not chat_id:
            log.warning(
                "Event %s (site %s) has no telegram_chat_id, skipping",
                event_id, ev.get("site_id"),
            )
            mark_event_error(event_id, "No telegram_chat_id for site")
            continue

        # базовий текст
        text = render_telegram_message(ev) or ""
        if not text.strip():
            text = "Новий інцидент WAF (без тексту)"

        # ТІЛЬКИ callback-кнопка (WebApp підключимо, коли буде HTTPS-домен)
        buttons = [
            InlineKeyboardButton(
                "Подивитись детальніше",
                callback_data=f"details:{event_id}",
            )
        ]

        keyboard = InlineKeyboardMarkup([buttons])

        try:
            await bot.send_message(
                chat_id=int(chat_id),
                text=text,
                reply_markup=keyboard,
            )
            mark_event_sent(event_id)
            log.info("Sent WAF event %s to chat %s", event_id, chat_id)
        except Exception as e:
            log.error(
                "Failed to send WAF event %s to chat %s: %s",
                event_id, chat_id, e,
            )
            mark_event_error(event_id, str(e))


# =====================================================================
#                запуск бота
# =====================================================================

def main() -> None:
    app = Application.builder().token(BOT_TOKEN).build()

    # Команди
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("help", help_cmd))

    # Статуси бота / юзерів
    app.add_handler(ChatMemberHandler(on_my_chat_member, ChatMemberHandler.MY_CHAT_MEMBER))
    app.add_handler(ChatMemberHandler(on_chat_member,     ChatMemberHandler.CHAT_MEMBER))

    # Повідомлення — кеш чатів
    app.add_handler(MessageHandler(filters.ALL, on_message))

    # Callback від кнопки «Подивитись детальніше»
    app.add_handler(CallbackQueryHandler(on_details_callback, pattern="^details:"))

    # Job: перевірка нових WAF-подій кожні 10 секунд
    if app.job_queue is not None:
        app.job_queue.run_repeating(poll_waf_events, interval=10, first=5)
    else:
        log.warning(
            "JobQueue недоступний, WAF-алерти не будуть надсилатися автоматично."
        )

    app.run_polling(close_loop=False)


if __name__ == "__main__":
    main()
