<?php
function gen_protect_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); // ~32-ую символів URL-safe
}
