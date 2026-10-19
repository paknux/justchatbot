# 🤖 justchatbot PHP

Chatbot berbasis PHP yang mendukung multiple AI provider (Gemini, ChatGPT, Claude) dengan fallback otomatis ke sistem berbasis kata kunci jika tidak ada API key yang tersedia.

## ✨ Fitur

- **Multi-provider AI** — mendukung Google Gemini, OpenAI ChatGPT, dan Anthropic Claude
- **Fallback otomatis** — jika semua provider gagal atau tidak dikonfigurasi, chatbot tetap berjalan dengan mode kata kunci (tanpa AI)
- **Riwayat percakapan** — percakapan tersimpan dalam sesi (hingga 20 pesan terakhir)
- **Tampilan dark mode** — UI modern dan responsif berbasis CSS variables
- **Jam real-time** — jam dan tanggal ditampilkan langsung di topbar
- **Info EC2 AWS** — menampilkan Instance ID dan Account ID jika dijalankan di AWS EC2
- **Keamanan output** — semua output AI di-sanitasi untuk mencegah XSS
- **Error handling** — semua error dikembalikan sebagai JSON; tidak ada output liar yang merusak respons

## 📁 Struktur File

```
/
├── index.php       # Frontend chatbot (UI + JavaScript)
├── chat.php        # Backend API handler
└── apikey.php      # Konfigurasi API key (jangan diunggah ke publik!)
```

## 🚀 Cara Instalasi

### Prasyarat

- PHP 7.4 atau lebih baru
- Ekstensi PHP: `curl`, `session`, `json`
- Web server: Apache / Nginx / PHP built-in server

### Langkah Instalasi

1. **Masuk sebagai root:**
   ```bash
   sudo su
   ```

2. **update sistem:**
   ```bash
   apt update
   ```

3. **Install Apache2 dan PHP:**
   ```bash
   apt install apache2 php libapache2-mod-php php-curl -y
   ```
4. **Enable Apache2:**
   ```bash
   systemctl enable --now apache2
   ```


5. **Kosongkan folder document root Apache:**
   ```bash
   rm -rf /var/www/html
   ```

6. **Clone repositori langsung ke document root Apache:**
   ```bash
   git clone https://github.com/username/justchatbot.git /var/www/html
   ```

7. **Masuk ke direktori project:**
   ```bash
   cd /var/www/html
   ```

8. **Edit file API key:**
   ```bash
   nano apikey.php
   ```
   Isi key sesuai provider yang digunakan:
   ```php
   $GEMINI_KEY    = 'AIza...';      // Dari aistudio.google.com/apikey (gratis)
   $OPENAI_KEY    = 'sk-...';       // Dari platform.openai.com
   $ANTHROPIC_KEY = 'sk-ant-...';   // Dari console.anthropic.com
   ```
   > Cukup isi salah satu atau lebih. Key yang kosong akan dilewati.

9. **Akses di browser:**
   ```
   http://<IP-Public-Anda>
   ```

---

> ### ⚙️ Keterangan Detail Tambahan
>
> #### Mengubah Model AI
>
> Di dalam `chat.php`, Anda bisa mengganti model yang digunakan:
>
> ```php
> $GEMINI_MODEL    = 'gemini-3.5-flash-lite';
> $OPENAI_MODEL    = 'gpt-4o-mini';
> $ANTHROPIC_MODEL = 'claude-sonnet-5-5';
> ```
>
> #### Mengubah System Prompt
>
> Ubah kepribadian chatbot dengan mengedit variabel berikut di `chat.php`:
>
> ```php
> $SYSTEM_PROMPT = 'Kamu adalah asisten chatbot yang ramah. Jawab singkat dan jelas dalam bahasa yang dipakai pengguna.';
> ```
>
> #### SSL di Localhost (XAMPP / Windows)
>
> Jika muncul error *"SSL certificate problem"*, tambahkan baris berikut di `apikey.php`:
>
> ```php
> $SSL_VERIFY = false;
> ```
>
> Atau letakkan file `cacert.pem` di folder yang sama dengan `chat.php`.
>
> ---
>
> ### 🔄 Urutan Prioritas Provider
>
> ```
> Gemini → ChatGPT → Claude → Rule-based (tanpa AI)
> ```
>
> Jika provider utama gagal atau API key tidak diisi, sistem otomatis beralih ke provider berikutnya.
>
> ---
>
> ### 💬 Mode Tanpa AI (Rule-based)
>
> Jika tidak ada API key yang dikonfigurasi, chatbot tetap dapat menjawab pertanyaan dasar:
>
> | Kata Kunci | Contoh Respons |
> |---|---|
> | `halo`, `hai`, `hello` | "Halo! Ada yang bisa saya bantu?" |
> | `siapa kamu` | "Saya chatbot sederhana yang dibuat dengan PHP." |
> | `jam`, `pukul`, `waktu` | "Sekarang pukul 14:30 WIB." |
> | `tanggal`, `hari ini` | "Hari ini tanggal 19-10-2026." |
> | `bantuan`, `help` | Menampilkan daftar perintah |
> | `terima kasih` | "Sama-sama!" |
> | `bye`, `dadah` | "Sampai jumpa lagi!" |
>
> ---
>
> ### 🔒 Keamanan
>
> Fitur keamanan yang sudah diterapkan:
> - Output AI di-render menggunakan `textContent` (bukan `innerHTML`) untuk mencegah XSS
> - Input pengguna dibatasi maksimal 1000 karakter
> - Semua error server dikembalikan sebagai JSON (tidak ada stack trace yang terekspos)
> - Riwayat percakapan dibatasi 20 pesan terakhir
>
> ---
>
> ### 🖥️ Tampilan
>
> - **Topbar** — menampilkan jam real-time, nama aplikasi, dan info instance AWS (jika ada)
> - **Chat area** — tampilan bubble chat untuk pesan pengguna dan bot
> - **Model info** — menampilkan nama provider dan model yang digunakan di setiap respons
> - **Tombol Reset** — menghapus riwayat percakapan saat ini
>
> ---
>
> ### 📡 API Endpoint
>
> **`POST /chat.php`**
>
> Request body (JSON):
> ```json
> { "message": "Halo!" }
> ```
>
> Response (JSON):
> ```json
> {
>   "reply": "Halo! Ada yang bisa saya bantu?",
>   "source": "gemini",
>   "model": "gemini-3.5-flash-lite",
>   "errors": []
> }
> ```
>
> Reset percakapan:
> ```json
> { "reset": true }
> ```
>
> ---
>
> ### 🛠️ Teknologi
>
> - **Backend:** PHP (native, tanpa framework)
> - **Frontend:** HTML, CSS, Vanilla JavaScript
> - **HTTP Client:** cURL (fallback ke `file_get_contents`)
> - **Session:** PHP native session

## 📄 Lisensi

Proyek ini bebas digunakan untuk keperluan pribadi maupun komersial.