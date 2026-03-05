# ChatApp Frontend (Astro)

Ini adalah frontend aplikasi chat berbasis [Astro](https://astro.build/), terintegrasi dengan backend API (serverless di Vercel).

## Fitur
- Menampilkan daftar user dari backend
- Siap dikembangkan untuk fitur chat, kontak, grup, dsb

## Struktur Project
- `src/pages/users.astro` — Contoh halaman fetch data user dari backend
- `astro.config.mjs`, `package.json` — Konfigurasi Astro

## Cara Menjalankan Lokal
1. Clone repo ini:
   ```bash
   git clone https://github.com/zzanarif16/chatpp.git
   cd chatapp
   ```
2. Install dependencies:
   ```bash
   npm install
   ```
3. Jalankan Astro dev server:
   ```bash
   npm run dev
   ```
4. Buka [http://localhost:4321/users](http://localhost:4321/users) untuk melihat daftar user.

## Konfigurasi URL Backend
Edit file `src/pages/users.astro` dan ganti URL backend sesuai endpoint API Anda (misal dari Vercel):
```js
const response = await fetch('https://chatapp-backend.vercel.app/api/users');
```

## Deploy ke Vercel
1. Push repo ke GitHub.
2. Login ke [Vercel](https://vercel.com/), hubungkan repo ini.
3. Deploy, Vercel akan memberikan URL publik.

## Integrasi Backend
- Backend serverless (Vercel) harus sudah online dan dapat diakses publik.
- Database menggunakan MySQL Clever Cloud.

## Lisensi
Bebas digunakan untuk pembelajaran dan pengembangan.
