<?php /** @var string $codeVar @var int $ttlVar */ ?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#14213d">
  <h2 style="margin:0 0 12px">Reset your password</h2>
  <p>Enter this code in the app to choose a new password:</p>
  <p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:16px 0"><?= htmlspecialchars($codeVar, ENT_QUOTES) ?></p>
  <p style="color:#5b6478">It expires in <?= (int)$ttlVar ?> minutes. If you did not ask for this, your password is unchanged and you can ignore this message.</p>
  <p style="color:#8a93a6;font-size:12px">A-Mail by Adverune. Your Email. Your Identity.</p>
</div>
