# ChatApp Frontend (Astro)

Frontend aplikasi chat menggunakan [Astro](https://astro.build/) untuk dideploy ke Vercel project `chatapp`.

URL frontend production saat ini:

- `https://chatapp-ekwhv4a7b-zzanarif16s-projects.vercel.app/`

## Prasyarat

- Node.js 18+
- Backend sudah online (misalnya Vercel project `chatapp-backend`)

## Jalankan Lokal

1. Masuk folder project:
   ```bash
   cd chatapp
   ```
2. Install dependency:
   ```bash
   npm install
   ```
3. Buat file `.env` dan isi URL backend:
   ```env
   PUBLIC_API_BASE_URL=http://localhost:3000
   ```
4. Jalankan dev server:
   ```bash
   npm run dev
   ```
5. Buka:
   - `http://localhost:4321/`
   - `http://localhost:4321/users`

## Environment Variable

Halaman `src/pages/users.astro` membaca backend URL dari:

- `PUBLIC_API_BASE_URL`

Contoh saat production:

```env
PUBLIC_API_BASE_URL=https://chatapp-backend-lh6y8i35j-zzanarif16s-projects.vercel.app
```

## Push ke GitHub Repo `chatapp`

Contoh jika remote belum ada:

```bash
git init
git add .
git commit -m "setup astro frontend for vercel"
git branch -M main
git remote add origin https://github.com/<username>/chatapp.git
git push -u origin main
```

Jika remote sudah ada:

```bash
git add .
git commit -m "update astro frontend for vercel"
git push
```

## Deploy ke Vercel Project `chatapp`

1. Import repo `chatapp` di Vercel.
2. Framework preset: `Astro`.
3. Build command: `npm run build`.
4. Output directory: `dist`.
5. Tambahkan Environment Variable:
   - `PUBLIC_API_BASE_URL=https://chatapp-backend-lh6y8i35j-zzanarif16s-projects.vercel.app`
6. Deploy.
