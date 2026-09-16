<?php
// Standalone "Add Item" page — full page instead of the app's Add Item
// modal, so it has its own bookmarkable URL. No password gate (removed by
// explicit request) — public, no login required.
//
// Writes directly into the live item store: both the `items` SQL table AND
// the sam_store key-value blob (sam_items / sam_{auctionId}_items) that the
// app actually reads on every navigation via syncFromKeyValueDB()/get_all.
// Writing only the SQL table would leave the item invisible in the app until
// an unrelated save_items call happened to sync it — writing only the kv blob
// would leave get_items()/export-backup (which reads the SQL table) stale.
// Both are updated together, inside a transaction with SELECT ... FOR UPDATE
// on the kv row, so two staff submitting at the same moment can't clobber
// each other's item_number assignment.
//
// Accepted risk (explicitly requested): if the app itself saves its item
// list (e.g. loading new item emails) using a stale in-browser copy from
// before this page's insert, that save's full-replace of the `items` table
// will still overwrite this row. There is no way to prevent that from a
// page outside the SPA's own state — only that it's no longer a *guaranteed*
// wipe on every save, the way a items-table-only insert would have been.

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
date_default_timezone_set('America/New_York'); // ETCC is Knoxville, TN — matches api.php's debug-log convention
require_once __DIR__ . '/security-helpers.php'; // sam_send_mail()

$CATEGORIES = [
    '100' => 'General Auto Repair / Car Items',
    '200' => 'Corvette Items',
    '300' => "Men's Items",
    '400' => "Women's Items",
    '500' => 'General Household',
    '600' => 'Framed Artwork or other Artwork to be Hung',
    '700' => 'Baskets / Gift Sets',
    '800' => 'Gift Certificates',
    '900' => 'Miscellaneous / Other',
];

function additem_load_env($envFile) {
    $env = [];
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v);
        }
    }
    return $env;
}
$env = additem_load_env(__DIR__ . '/.env');

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'], $env['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Exception $e) {
    error_log('[add-item] DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection error.');
}

// Password gate removed (explicit request) — this page now writes directly
// into the live `items` table/kv blob with no authentication at all. Unlike
// donate-item.php (which only reaches the isolated donated_items_pending
// queue), anyone with this URL can now insert real auction items.


// ETCC member roster, for the "ETCC Member Name" dropdown. Read from the same
// sam_members key-value blob the app's (now-orphaned) Member Database screen
// used — {member_number, last_name, first_name, primary_email, cell_phone}.
$members = [];
try {
    $val = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_members' LIMIT 1")->fetchColumn();
    if ($val) {
        $decoded = json_decode($val, true);
        if (is_array($decoded)) $members = $decoded;
    }
} catch (Exception $e) { /* empty dropdown if unavailable */ }
usort($members, fn($a, $b) => strcasecmp(($a['last_name'] ?? '') . ($a['first_name'] ?? ''), ($b['last_name'] ?? '') . ($b['first_name'] ?? '')));

// ── Authenticated: handle the item form ─────────────────────────────────
$errors = [];
$success = false;
$values = [
    'etccMemberName' => '', 'memberEmail' => '', 'category' => '', 'description' => '', 'itemValue' => '', 'reserveAmount' => '',
    'donorName' => '', 'donorEmail' => '', 'donorPhone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['description'])) {
    foreach (['etccMemberName', 'memberEmail', 'category', 'description', 'itemValue', 'reserveAmount', 'donorName', 'donorEmail', 'donorPhone'] as $f) {
        $values[$f] = trim((string)($_POST[$f] ?? ''));
    }

    if ($values['etccMemberName'] === '') $errors[] = 'ETCC Member Name is required.';
    if ($values['donorName'] === '') $errors[] = 'Donor Name is required.';
    if ($values['donorEmail'] === '' || !filter_var($values['donorEmail'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid Donor Email is required.';
    if ($values['description'] === '') $errors[] = 'Description is required.';
    if (!array_key_exists($values['category'], $CATEGORIES)) $errors[] = 'Choose a valid item category.';
    if ($values['itemValue'] === '') $errors[] = 'Item Value is required.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $auctionId = (string)($pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_current_auction' LIMIT 1")->fetchColumn() ?: '');
            $itemsKey = $auctionId !== '' ? "sam_{$auctionId}_items" : 'sam_items';

            // Lock the kv row for the duration of the read-modify-write so two
            // concurrent submissions can't both compute the same item_number.
            $stmt = $pdo->prepare("SELECT `value` FROM sam_store WHERE `key` = ? FOR UPDATE");
            $stmt->execute([$itemsKey]);
            $rawItems = $stmt->fetchColumn();
            $items = $rawItems ? (json_decode($rawItems, true) ?: []) : [];

            // Same numbering scheme as Items.nextItemNumber() in index.html.
            $prefix = $values['category'] . '-';
            $max = 0;
            foreach ($items as $it) {
                $num = (string)($it['item_number'] ?? '');
                if (strpos($num, $prefix) === 0) {
                    $seq = (int)substr($num, strlen($prefix));
                    if ($seq > $max) $max = $seq;
                }
            }
            $itemNumber = $prefix . ($max + 1);

            $newItem = [
                'item_number'      => $itemNumber,
                'category_code'    => $values['category'],
                'category_name'    => $CATEGORIES[$values['category']],
                'description'      => $values['description'],
                'item_value'       => $values['itemValue'],
                'reserve_amount'   => $values['reserveAmount'] !== '' ? $values['reserveAmount'] : '0',
                'donor_name'       => $values['donorName'],
                'donor_email'      => $values['donorEmail'],
                'donor_phone'      => $values['donorPhone'],
                'etcc_member_name' => $values['etccMemberName'],
                'email_date'       => date('m/d/Y h:i A'),
                'loaded_date'      => '',
                'email_message_id' => '',
                'source'           => 'Added',
            ];
            $items[] = $newItem;

            $kvStmt = $pdo->prepare(
                "INSERT INTO sam_store (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
            );
            $kvStmt->execute([$itemsKey, json_encode($items)]);

            $sqlStmt = $pdo->prepare(
                "INSERT INTO items (auction_id, item_number, item_category, email_message_id, description, item_value, reserve_amount, donor_name, donor_email, donor_phone, submission_date)
                 VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE item_category=VALUES(item_category), description=VALUES(description), item_value=VALUES(item_value), reserve_amount=VALUES(reserve_amount), donor_name=VALUES(donor_name), donor_email=VALUES(donor_email), donor_phone=VALUES(donor_phone)"
            );
            $sqlStmt->execute([
                $auctionId, $itemNumber, $CATEGORIES[$values['category']],
                $values['description'], $values['itemValue'], $newItem['reserve_amount'],
                $values['donorName'], $values['donorEmail'], $values['donorPhone'], date('Y-m-d H:i:s'),
            ]);

            $pdo->commit();

            // Email a copy of this submission, in addition to the database
            // save above, if configured (Settings → Item Donation Confirmation
            // Email). Best-effort — a failed send never blocks or fails the
            // item save itself.
            try {
                $settingsVal = $pdo->query("SELECT `value` FROM sam_store WHERE `key` = 'sam_settings' LIMIT 1")->fetchColumn();
                $emailTo = '';
                $emailCc = '';
                $emailBcc = '';
                $emailSubject = 'New Item Donation Submitted';
                if ($settingsVal) {
                    $s = json_decode($settingsVal, true);
                    $emailTo = trim((string)($s['donationEmailTo'] ?? ''));
                    $emailCc = trim((string)($s['donationEmailCc'] ?? ''));
                    $emailBcc = trim((string)($s['donationEmailBcc'] ?? ''));
                    $emailSubject = trim((string)($s['donationEmailSubject'] ?? '')) ?: $emailSubject;
                }
                // The selected ETCC member's email (auto-filled from the member
                // dropdown) takes priority as the To: recipient over the static
                // Settings-configured address, so the confirmation goes straight
                // to the member who submitted the item. Falls back to the
                // Settings To address if no member email is available.
                if ($values['memberEmail'] !== '' && filter_var($values['memberEmail'], FILTER_VALIDATE_EMAIL)) {
                    $emailTo = $values['memberEmail'];
                }
                if ($emailTo !== '' && sam_parse_addr_list($emailTo)) {
                    $logoUrl = 'https://etccapps.com/apps/sam/Images/ETCClogoWhiteBackground.png';
                    $rows = [
                        'Item #'           => $itemNumber,
                        'ETCC Member Name' => $values['etccMemberName'],
                        'Category'         => $values['category'] . ' — ' . $CATEGORIES[$values['category']],
                        'Description'      => $values['description'],
                        'Item Value'       => $values['itemValue'],
                        'Reserve Amount'   => $newItem['reserve_amount'],
                        'Donor Name'       => $values['donorName'],
                        'Donor Email'      => $values['donorEmail'],
                        'Donor Phone'      => $values['donorPhone'],
                    ];
                    $rowsHtml = '';
                    foreach ($rows as $label => $value) {
                        $rowsHtml .= '<tr>'
                            . '<td style="padding:10px 16px;border-bottom:1px solid #e3e6ea;color:#667085;font-size:13px;font-weight:600;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($label) . '</td>'
                            . '<td style="padding:10px 16px;border-bottom:1px solid #e3e6ea;color:#1a1a1a;font-size:13px;">' . nl2br(htmlspecialchars((string)$value)) . '</td>'
                            . '</tr>';
                    }
                    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
                        . '<body style="margin:0;padding:0;background:#f4f6f8;font-family:\'Segoe UI\',Arial,sans-serif;">'
                        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:28px 16px;">'
                        . '<tr><td align="center">'
                        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;border:1px solid #e3e6ea;overflow:hidden;">'
                        . '<tr><td style="padding:28px 24px 8px;text-align:center;">'
                        . '<img src="' . htmlspecialchars($logoUrl) . '" alt="ETCC Logo" width="64" height="64" style="display:block;margin:0 auto 12px;border-radius:6px;">'
                        . '<div style="font-size:20px;font-weight:700;color:#1a1a1a;">Item Donation Submitted</div>'
                        . '<div style="font-size:13px;color:#667085;margin-top:2px;">East Tennessee Corvette Club Silent Auction</div>'
                        . '</td></tr>'
                        . '<tr><td style="padding:16px 24px 4px;">'
                        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e6ea;border-radius:8px;overflow:hidden;">' . $rowsHtml . '</table>'
                        . '</td></tr>'
                        . '<tr><td style="padding:20px 24px 24px;text-align:center;color:#667085;font-size:11px;">'
                        . '&copy; 2026 East Tennessee Corvette Club &middot; Knoxville, TN'
                        . '</td></tr>'
                        . '</table></td></tr></table></body></html>';
                    sam_send_mail($emailTo, $emailSubject, $html, $env, $emailCc, $emailBcc, true);
                }
            } catch (Exception $e) { /* email is best-effort, item save already succeeded */ }

            $success = true;
            $lastItemNumber = $itemNumber;
            $values = [
                'etccMemberName' => '', 'memberEmail' => '', 'category' => '', 'description' => '', 'itemValue' => '', 'reserveAmount' => '',
                'donorName' => '', 'donorEmail' => '', 'donorPhone' => '',
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[add-item] ' . $e->getMessage());
            $errors[] = 'Could not save the item right now — please try again in a moment.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Silent Auction Form — Item Donation</title>
<style>
  :root { --accent:#0071e3; --accent-dark:#0058b0; --ink:#1a1a1a; --muted:#667085; --line:#e3e6ea; --bg:#f4f6f8; --panel:#fff; --good:#147d3a; --red:#c62828; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 "Segoe UI", Arial, sans-serif; color: var(--ink); background: var(--bg); margin:0; padding: 28px 16px 60px; }
  .wrap { max-width: 560px; margin: 0 auto; }
  .logo { display:block; height:64px; width:64px; object-fit:contain; margin: 0 auto 10px; border-radius:6px; }
  h1 { font-size: 20px; text-align: center; margin: 0 0 2px; }
  .sub { text-align:center; color:var(--muted); font-size:13px; margin-bottom:22px; }
  .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 22px 24px; }
  .form-row { margin: 12px 0; }
  .two-col { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  label { display:block; font-weight:600; font-size:13px; margin-bottom:4px; }
  input[type=text], input[type=email], input[type=tel], input[type=date], textarea, select {
    width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:7px; font-size:14px; font-family:inherit; color: var(--ink); background: #fff;
  }
  textarea { resize:vertical; min-height:70px; }
  .btn { background: var(--accent); border: 1px solid var(--accent-dark); color:#fff; padding: 11px 18px; border-radius:8px; font-size:15px; font-weight:700; cursor:pointer; width:100%; margin-top:10px; }
  .btn:hover { background: var(--accent-dark); }
  .btn-row { display:flex; gap:10px; }
  .btn-row .btn { margin-top:10px; }
  .btn-secondary { background:#fff; border:1px solid var(--line); color: var(--ink); }
  .btn-secondary:hover { background:#f4f6f8; }
  .errors { background:#fff5f5; border-left:4px solid var(--red); border-radius:6px; padding:10px 14px; margin-bottom:14px; color:var(--red); font-size:13px; }
  .errors ul { margin:4px 0 0; padding-left:18px; }
  .success { background:#f0faf3; border-left:4px solid var(--good); border-radius:6px; padding:10px 14px; margin-bottom:14px; color:var(--good); font-size:13px; font-weight:600; }
  .footer { text-align:center; color:var(--muted); font-size:11px; margin-top:22px; }
</style>
</head>
<body>
<div class="wrap">
  <img src="Images/ETCClogoWhiteBackground.png" alt="ETCC Logo" class="logo">
  <h1>Silent Auction Form</h1>
  <div class="sub">Item Donation</div>
  <div class="panel">
    <?php if ($success): ?>
      <div class="success">Item <?php echo htmlspecialchars($lastItemNumber); ?> was added successfully. Closing…</div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="errors"><strong>Please fix the following:</strong><ul>
        <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
      </ul></div>
    <?php endif; ?>
    <form method="post" novalidate>
      <div class="form-row">
        <label for="f-member">ETCC Member Name *</label>
        <select id="f-member" name="etccMemberName" onchange="samFillMemberEmail()">
          <option value="">— choose —</option>
          <?php foreach ($members as $m): ?>
            <?php
              $last = trim($m['last_name'] ?? '');
              $first = trim($m['first_name'] ?? '');
              if ($last === '' && $first === '') continue;
              $mName = $last !== '' && $first !== '' ? "$last, $first" : ($last ?: $first);
              $mEmail = trim($m['primary_email'] ?? '');
            ?>
            <option value="<?php echo htmlspecialchars($mName); ?>" data-email="<?php echo htmlspecialchars($mEmail); ?>" <?php echo $values['etccMemberName'] === $mName ? 'selected' : ''; ?>><?php echo htmlspecialchars($mName); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label for="f-member-email">Member Email</label>
        <input type="email" id="f-member-email" name="memberEmail" readonly style="background:#f4f6f8;color:#667085;" value="<?php echo htmlspecialchars($values['memberEmail'] ?? ''); ?>">
      </div>
      <script>
        function samFillMemberEmail() {
          var sel = document.getElementById('f-member');
          var opt = sel.options[sel.selectedIndex];
          document.getElementById('f-member-email').value = (opt && opt.dataset.email) || '';
        }
      </script>
      <div class="form-row">
        <label for="f-donor">Donor Name *</label>
        <input type="text" id="f-donor" name="donorName" required value="<?php echo htmlspecialchars($values['donorName']); ?>">
      </div>
      <div class="form-row">
        <label for="f-email">Donor Email *</label>
        <input type="email" id="f-email" name="donorEmail" required value="<?php echo htmlspecialchars($values['donorEmail']); ?>">
      </div>
      <div class="form-row">
        <label for="f-phone">Donor Phone</label>
        <input type="tel" id="f-phone" name="donorPhone" placeholder="(123) 456-7890" value="<?php echo htmlspecialchars($values['donorPhone']); ?>">
      </div>
      <div class="form-row">
        <label for="f-desc">Item Description *</label>
        <textarea id="f-desc" name="description" style="min-height:120px;" required><?php echo htmlspecialchars($values['description']); ?></textarea>
      </div>
      <div class="form-row">
        <label for="f-category">Item Category *</label>
        <select id="f-category" name="category">
          <option value="">— choose —</option>
          <?php foreach ($CATEGORIES as $code => $label): ?>
            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $values['category'] === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($code . ' — ' . $label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="two-col">
        <div class="form-row">
          <label for="f-value">Item Value *</label>
          <input type="text" id="f-value" name="itemValue" placeholder="$0.00" required value="<?php echo htmlspecialchars($values['itemValue']); ?>">
        </div>
        <div class="form-row">
          <label for="f-reserve">Reserve Amount</label>
          <input type="text" id="f-reserve" name="reserveAmount" placeholder="$0.00" value="<?php echo htmlspecialchars($values['reserveAmount']); ?>">
        </div>
      </div>
      <script>
        (function () {
          var phoneInput = document.getElementById('f-phone');
          function formatPhone(v) {
            var digits = v.replace(/\D/g, '').slice(0, 10);
            if (digits.length < 4) return digits;
            if (digits.length < 7) return '(' + digits.slice(0, 3) + ') ' + digits.slice(3);
            return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
          }
          phoneInput.addEventListener('input', function () { phoneInput.value = formatPhone(phoneInput.value); });
          phoneInput.value = formatPhone(phoneInput.value);

          function formatCurrency(v) {
            var n = parseFloat(String(v).replace(/[^0-9.]/g, ''));
            if (isNaN(n)) return '';
            return '$' + Math.round(n);
          }
          ['f-value', 'f-reserve'].forEach(function (id) {
            var el = document.getElementById(id);
            el.addEventListener('blur', function () {
              if (el.value.trim() !== '') el.value = formatCurrency(el.value);
            });
            if (el.value.trim() !== '') el.value = formatCurrency(el.value);
          });
        })();
      </script>
      <div class="btn-row">
        <button type="button" class="btn btn-secondary" onclick="window.close(); setTimeout(function(){ location.href='index.html'; }, 150);">Cancel</button>
        <button type="submit" class="btn">Donate Item</button>
      </div>
    </form>
  </div>
  <div class="footer">&copy; 2026 East Tennessee Corvette Club &middot; Knoxville, TN &middot; <a href="mailto:etccwebsite.webmanager@gmail.com">etccwebsite.webmanager@gmail.com</a></div>
</div>
<?php if ($success): ?>
<script>
  // Donate Item successfully added the item server-side (full-page POST) —
  // close the form now instead of leaving it open for another entry, per
  // explicit request. Same window.close()-then-fallback-redirect pattern the
  // Cancel button already uses, for when this wasn't opened via window.open()
  // (e.g. reached directly) and window.close() is a no-op.
  window.close();
  setTimeout(function(){ location.href = 'index.html'; }, 150);
</script>
<?php endif; ?>
</body>
</html>
