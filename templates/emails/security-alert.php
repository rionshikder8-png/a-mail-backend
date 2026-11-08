<?php /** @var string $codeVar @var int $ttlVar */ ?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#14213d">
  <h2 style="margin:0 0 12px">Your security code</h2>
  <p>Use this code to continue:</p>
  <p style="font-size:32px;letter-spacing:8px;font-weight:700;margin:16px 0"><?= htmlspecialchars($codeVar, ENT_QUOTES) ?></p>
  <p style="color:#5b6478">It expires in <?= (int)$ttlVar ?> minutes. A-Mail will never ask you to share this code.</p>
  <p style="color:#8a93a6;font-size:12px">A-Mail by Adverune. Your Email. Your Identity.</p>
</div>
