<?php /** @var string $nameVar @var string $addressVar */ ?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#14213d">
  <h2 style="margin:0 0 12px">Welcome to A-Mail, <?= htmlspecialchars($nameVar, ENT_QUOTES) ?></h2>
  <p>Your new address is <strong><?= htmlspecialchars($addressVar, ENT_QUOTES) ?></strong>.</p>
  <p style="color:#8a93a6;font-size:12px">A-Mail by Adverune. Your Email. Your Identity.</p>
</div>
