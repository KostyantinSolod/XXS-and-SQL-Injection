import os
import psycopg2
from aiogram import Bot, Dispatcher, types
from aiogram.utils import executor

# Токен з .env
BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN")

bot = Bot(token=BOT_TOKEN)
dp = Dispatcher(bot)

# Підключення до PostgreSQL
db = psycopg2.connect(
    dbname=os.getenv("DB_NAME"),
    user=os.getenv("DB_USER"),
    password=os.getenv("DB_PASS"),
    host=os.getenv("DB_HOST"),
    port=os.getenv("DB_PORT")
)
cursor = db.cursor()

@dp.message_handler(commands=['start'])
async def start_handler(message: types.Message):
    args = message.get_args()  # те, що після /start
    if args.isdigit():
        user_id = int(args)
        chat_id = message.chat.id

        # Прив'язуємо chat_id до юзера
        cursor.execute("UPDATE users SET telegram_id = %s WHERE id = %s", (chat_id, user_id))
        db.commit()

        await message.answer("✅ Ваш Telegram успішно прив'язано до акаунта!")
    else:
        await message.answer("Привіт! Щоб прив'язати акаунт, використайте кнопку на сайті.")

if __name__ == "__main__":
    executor.start_polling(dp, skip_updates=True)
