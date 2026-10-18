<?php
session_start();

/** GET/PUT sederhana ke layanan metadata EC2. Mengembalikan isi respons jika HTTP 200, selain itu null. */
function metaRequest(string $method, string $path, string $headers = ''): ?string
{
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => $headers,
        'content'       => '',
        'timeout'       => 0.6,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents('http://169.254.169.254' . $path, false, $ctx);
    $ok   = isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false;
    return ($body !== false && $ok) ? $body : null;
}

/** Instance ID dan Account ID dari metadata EC2 (hanya ada jika dijalankan di EC2). */
function ec2Info(): array
{
    $cached = $_SESSION['ec2info'] ?? null;
    if ($cached && ($cached['found'] || time() - $cached['t'] < 60)) {
        return $cached['data'];
    }
    $data = ['instance' => '-', 'account' => '-'];
    $found = false;

    $token = metaRequest('PUT', '/latest/api/token', "X-aws-ec2-metadata-token-ttl-seconds: 60\r\n");
    $hdr   = $token ? "X-aws-ec2-metadata-token: {$token}\r\n" : '';
    $json  = metaRequest('GET', '/latest/dynamic/instance-identity/document', $hdr);
    $doc   = $json ? json_decode($json, true) : null;
    if (is_array($doc) && !empty($doc['instanceId'])) {
        $data  = ['instance' => $doc['instanceId'], 'account' => $doc['accountId'] ?? '-'];
        $found = true;
    }
    $_SESSION['ec2info'] = ['t' => time(), 'found' => $found, 'data' => $data];
    return $data;
}

$ec2 = ec2Info();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chatbot PHP</title>
<style>
  :root {
    --bg: #0f1115;
    --panel: #181b22;
    --panel-border: #272b35;
    --accent: #6366f1;
    --text: #e6e8ee;
    --muted: #8b91a0;
    --bot-bg: #242833;
    --input-bg: #11141a;
    --input-border: #2f3441;
  }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); display: flex; flex-direction: column; min-height: 100vh; }
  .chat { width: 100%; max-width: 480px; height: calc(100vh - 48px - 32px); background: var(--panel); border: 1px solid var(--panel-border); border-radius: 14px; box-shadow: 0 8px 32px rgba(0,0,0,.5); display: flex; flex-direction: column; overflow: hidden; }
  .head { background: #1e2230; border-bottom: 1px solid var(--panel-border); padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; }
  .head .sub { display: block; font-size: 12px; color: var(--muted); margin-top: 2px; }
  .topbar { height: 48px; flex-shrink: 0; background: #12151b; border-bottom: 1px solid var(--panel-border); padding: 0 20px; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 12px; }
  .topbar .clock { font-size: 13px; color: var(--muted); }
  .topbar .brand { font-weight: 600; text-align: center; }
  .topbar .host { font-size: 13px; color: var(--muted); text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .wrap { flex: 1; display: flex; justify-content: center; align-items: center; padding: 16px; min-height: 0; }
  .head button { background: #2a2f3d; color: var(--text); border: 1px solid var(--input-border); border-radius: 6px; padding: 4px 10px; cursor: pointer; }
  .head button:hover { background: #343a4b; }
  #log { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
  #log::-webkit-scrollbar { width: 8px; }
  #log::-webkit-scrollbar-thumb { background: #2f3441; border-radius: 4px; }
  .msg { max-width: 80%; padding: 9px 13px; border-radius: 14px; line-height: 1.4; word-wrap: break-word; }
  .msg .text { white-space: pre-wrap; }
  .user { align-self: flex-end; background: var(--accent); color: #fff; border-bottom-right-radius: 4px; }
  .bot  { align-self: flex-start; background: var(--bot-bg); color: var(--text); border-bottom-left-radius: 4px; }
  .meta { margin-top: 6px; font-size: 11px; color: var(--muted); }
  .warn { background: #3a2a1a; color: #f3c98b; font-size: 13px; }
  form { display: flex; gap: 8px; padding: 12px; border-top: 1px solid var(--panel-border); }
  input { flex: 1; padding: 10px 12px; background: var(--input-bg); color: var(--text); border: 1px solid var(--input-border); border-radius: 8px; font-size: 15px; outline: none; }
  input::placeholder { color: var(--muted); }
  input:focus { border-color: var(--accent); }
  form button { background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 0 18px; cursor: pointer; font-size: 15px; }
  form button:hover { filter: brightness(1.1); }
  form button:disabled { opacity: .5; cursor: default; }
</style>
</head>
<body>
<div class="topbar">
  <div class="clock" id="clock"></div>
  <div class="brand">Chatbot PHP</div>
  <div class="host" title="Instance ID dan Account ID AWS">Instance: <?= htmlspecialchars($ec2['instance']) ?> · Akun: <?= htmlspecialchars($ec2['account']) ?></div>
</div>
<div class="wrap">
<div class="chat">
  <div class="head">
    <div><strong>Chatbot PHP</strong><span class="sub" id="modelInfo">Model: belum ada pesan</span></div>
    <button id="reset" type="button">Reset</button>
  </div>
  <div id="log">
    <div class="msg bot"><div class="text">Halo! Ketik "bantuan" untuk melihat apa yang bisa saya jawab.</div></div>
  </div>
  <form id="form">
    <input id="text" placeholder="Tulis pesan..." autocomplete="off" maxlength="1000" autofocus>
    <button id="send">Kirim</button>
  </form>
</div>
</div>

<script>
const log = document.getElementById('log');
const form = document.getElementById('form');
const text = document.getElementById('text');
const send = document.getElementById('send');
const modelInfo = document.getElementById('modelInfo');

// ---------- Jam & tanggal (format: Minggu, 18 Okt 2026, pukul 22:30:45) ----------
const HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const clock = document.getElementById('clock');

function updateClock() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  clock.textContent = HARI[d.getDay()] + ', ' + d.getDate() + ' ' + BULAN[d.getMonth()] + ' ' + d.getFullYear()
    + ', pukul ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}
updateClock();
setInterval(updateClock, 1000);

const PROVIDER_LABEL = { gemini: 'Gemini', chatgpt: 'ChatGPT', claude: 'Claude', 'rule-based': 'Tanpa AI' };

function addMsg(content, who) {
  const el = document.createElement('div');
  el.className = 'msg ' + who;
  const t = document.createElement('div');
  t.className = 'text';
  t.textContent = content;          // textContent mencegah XSS
  el.appendChild(t);
  log.appendChild(el);
  log.scrollTop = log.scrollHeight;
  return el;
}

function setMeta(el, data) {
  const label = (PROVIDER_LABEL[data.source] || data.source) + ' · ' + data.model;
  const meta = document.createElement('div');
  meta.className = 'meta';
  meta.textContent = label;
  el.appendChild(meta);
  modelInfo.textContent = 'Model: ' + label;
}

async function post(body) {
  const res = await fetch('chat.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const raw = await res.text();
  try { return JSON.parse(raw); }
  catch (e) { throw new Error('Respons server bukan JSON: ' + raw.slice(0, 300)); }
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const message = text.value.trim();
  if (!message) return;
  addMsg(message, 'user');
  text.value = '';
  send.disabled = true;
  const typing = addMsg('...', 'bot');
  try {
    const data = await post({ message });
    typing.querySelector('.text').textContent = data.reply;
    if (data.model) setMeta(typing, data);
    if (data.errors && data.errors.length) {
      addMsg('⚠ ' + data.errors.join('\n'), 'bot warn');
    }
  } catch (err) {
    typing.querySelector('.text').textContent = 'Terjadi kesalahan: ' + err.message;
  }
  send.disabled = false;
  text.focus();
  log.scrollTop = log.scrollHeight;
});

document.getElementById('reset').addEventListener('click', async () => {
  await post({ reset: true });
  log.innerHTML = '';
  modelInfo.textContent = 'Model: belum ada pesan';
  addMsg('Percakapan direset. Silakan mulai lagi.', 'bot');
});
</script>
</body>
</html>