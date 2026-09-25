# Frontend – Ganglbauer Zeitmanagement

Vue 3 (Composition API) + TypeScript + Vite + Pinia + Vue Router.

## Befehle

```bash
npm install        # Abhaengigkeiten installieren
npm run dev        # Devserver auf http://localhost:5173
npm run build      # Produktionsbuild nach dist/
npm run type-check # TypeScript pruefen
npm run lint       # ESLint + oxlint
npm run format     # Prettier
```

## Struktur

```
src/
├── api/          Axios-Client, Fehlerübersetzung und API-Typen
├── components/   AppNav (Navigation) und TimerBar (Start/Stopp einer Arbeit)
├── router/       Routen inkl. Auth-Guard
├── stores/       Pinia-Stores: auth (JWT) und workshop (Arbeiten/Übersicht)
├── utils/        Datums-, Dauer- und Prioritätsformatierung
└── views/        Login, Übersicht, Arbeiten, Brauche Arbeit, Auswertung, Einstellungen
```

## API-Anbindung

Im Devmodus leitet der Vite-Proxy (`vite.config.ts`) alle `/api`-Aufrufe an
`http://127.0.0.1:8000` weiter. Eine andere Backend-Adresse setzt du über
`VITE_PROXY_TARGET`; soll das Frontend direkt gegen eine fremde API sprechen,
`VITE_API_BASE_URL` in einer `.env.local` setzen (siehe `.env.example`).

Das JWT wird nach dem Login im `localStorage` unter `gz_token` abgelegt und vom
Axios-Interceptor als `Authorization: Bearer …` mitgeschickt. Bei einem 401
loggt der Auth-Store automatisch aus.
