# TicketUp API

API REST de gestion d'événements et de billetterie : catalogue d'événements, catégories, lieux,
organisations et gestion des membres, authentification JWT.

Ce dépôt ne contient **que le back-end**. Il est conçu pour être consommé par des clients externes —
une application mobile React Native et un front web Angular. La section
[« Connecter un client externe »](#connecter-un-client-externe) est là pour ça.

## Sommaire

- [Stack technique](#stack-technique)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Lancer le serveur](#lancer-le-serveur)
- [Conventions de l'API](#conventions-de-lapi)
- [Authentification](#authentification)
- [CORS](#cors)
- [Connecter un client externe](#connecter-un-client-externe)
  - [Front web Angular](#front-web-angular)
  - [Application mobile React Native](#application-mobile-react-native)
- [Pièges à connaître côté client](#pièges-à-connaître-côté-client)
- [Documentation complémentaire](#documentation-complémentaire)

## Stack technique

| Composant | Version |
|---|---|
| PHP | ≥ 8.2 |
| Symfony | 7.2 |
| API Platform | 4.1 |
| Doctrine ORM | 3.x |
| Base de données | **PostgreSQL 16** |
| Auth | LexikJWTAuthenticationBundle + Gesdinet (refresh tokens) |
| CORS | NelmioCorsBundle |

> Le projet ne contient **ni tests, ni linter, ni analyse statique**. Les changements se vérifient en
> appelant les endpoints (`curl`, Swagger UI sur `/api/docs`).

## Prérequis

- PHP ≥ 8.2 avec les extensions `ctype`, `iconv`, `pdo_pgsql`, `openssl`
- Composer 2
- Docker (pour la base) ou un PostgreSQL 16 local
- Le CLI [Symfony](https://symfony.com/download) (facultatif mais pratique)

## Installation

```bash
git clone https://github.com/jehovanie/ticketUp.git
cd ticketUp/code/api
composer install
```

**1. Base de données**

```bash
docker compose up -d database
docker compose port database 5432        # le port hôte est assigné dynamiquement
```

Renseigner ensuite `DATABASE_URL` dans `.env.local` (fichier non versionné) :

```dotenv
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:<PORT>/app?serverVersion=16&charset=utf8"
```

**2. Clés JWT** — obligatoire, `config/jwt/*.pem` est ignoré par git :

```bash
php bin/console lexik:jwt:generate-keypair
```

Si vous choisissez une passphrase, reportez-la dans `JWT_PASSPHRASE` (`.env.local`).

**3. Schéma et jeu de démonstration**

```bash
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
```

> ⚠️ `doctrine:fixtures:load` **purge la base**, table `"user"` et `refresh_tokens` comprises.
> ⚠️ Sur une base vierge, `doctrine:migrations:migrate` ne suffit pas (la première migration a un
> `up()` vide) : utiliser `doctrine:schema:create`.

Comptes de démonstration — mot de passe commun `Password123!` :

| Compte | Rôle |
|---|---|
| `admin@ticketup.mg` | `ROLE_SUPER_ADMIN` (membre d'aucune organisation) |
| `hery.rakoto@techmada.mg` | responsable de *Tech Madagascar* et *Madagascar Events* |
| `lova.randria@techmada.mg` | admin de *Tech Madagascar* |
| `mamy.rabe@techmada.mg` | membre de *Tech Madagascar* |

## Lancer le serveur

```bash
symfony server:start -d          # http://localhost:8000
# ou
php -S localhost:8000 -t public
```

Pour qu'un téléphone ou un émulateur du réseau local puisse joindre l'API, il faut écouter sur
toutes les interfaces :

```bash
symfony server:start  --port=8000 --allow-all-ip --no-tls
```

Vérifications rapides :

```bash
curl http://localhost:8000/api/events            # doit renvoyer une liste paginée
open http://localhost:8000/api/docs              # Swagger UI
php bin/console debug:router                     # liste réelle des routes
```

## Conventions de l'API

Tout est préfixé par `/api`. Trois choses à savoir avant d'écrire le moindre client :

**1. Deux formats de réponse coexistent.** Les endpoints à contrôleur personnalisé renvoient une
enveloppe maison :

```json
{
  "message": "Liste des événements récupérée avec succès",
  "status": 200,
  "data": { "itemsTotal": 42, "currentPage": 1, "nombreParPage": 10, "items": [] }
}
```

Les endpoints laissés au pipeline natif d'API Platform (`/api/categories/{id}`, `/api/locations`,
`/api/location/{id}`, `/api/organizers`, `/api/organizer/{id}`, `POST /api/events`) répondent en
**JSON-LD / Hydra** (`@context`, `@id`, `member`). Envoyer `Accept: application/json` pour obtenir
du JSON simple à la place.

Quelques endpoints échappent aux deux : `POST /api/auth/register`, `POST /api/auth/login`,
`POST /api/auth/refresh` et `GET /api/user/me` renvoient un objet plat, sans enveloppe.
Un client sérieux prévoit donc **une fonction `unwrap()` centrale** plutôt que de traiter
`response.data.data` au cas par cas.

**2. Pagination.** Paramètres `page` (défaut 1) et `itemsPerPage`, bornés côté serveur :
1–20 (défaut 10) pour les événements, 1–50 (défaut 20) pour les catégories. Les valeurs hors bornes
sont ramenées silencieusement dans l'intervalle : **toujours lire `nombreParPage` de la réponse**
plutôt que de supposer que la valeur demandée a été appliquée.

**3. Erreurs métier.** Elles reprennent l'enveloppe, avec `data: null` :

```json
{ "message": "Vous n'avez pas les droits sur cette organisation", "status": 403, "data": null }
```

La liste complète des routes, paramètres et formes de réponse est dans
[`API_ENDPOINTS.md`](API_ENDPOINTS.md).

## Authentification

JWT porté par l'en-tête `Authorization: Bearer <token>`, avec rotation par refresh token.

| Jeton | Durée de vie | Remarque |
|---|---|---|
| `token` (access) | **15 minutes** | à envoyer à chaque requête |
| `refresh_token` | **30 jours** | **usage unique** (`single_use: true`) |

### Cycle de vie

```
POST /api/auth/register  ──► 201 { token, refresh_token, user }
POST /api/auth/login     ──► 200 { token, refresh_token }
        │
        ├─ requêtes avec  Authorization: Bearer <token>
        │
        └─ 401 après 15 min
                 │
                 ▼
POST /api/auth/refresh { "refresh_token": "…" }
                 ├─ 200 { token, refresh_token }   ← les DEUX sont renouvelés, on remplace les deux
                 └─ 401  ► refresh expiré ou déjà consommé ► purge du stockage ► écran de connexion
```

Corps attendus :

```jsonc
// POST /api/auth/register
{ "email": "…", "password": "…", "firstname": "…", "lastname": "…", "phone": "…", "language": "fr" }
// POST /api/auth/login   — le champ s'appelle "email", pas "username"
{ "email": "…", "password": "…" }
// POST /api/auth/refresh
{ "refresh_token": "…" }
```

> ⚠️ **`single_use: true` est le piège n°1 des clients.** Chaque appel à `/api/auth/refresh`
> invalide le refresh token utilisé. Si deux requêtes tombent en 401 en même temps et déclenchent
> chacune un refresh, la seconde utilise un jeton déjà consommé → `401` → déconnexion intempestive.
> **Le rafraîchissement doit être sérialisé** : un seul appel en vol, les autres requêtes attendent
> son résultat. Les deux exemples ci-dessous implémentent exactement ça.

### Endpoints protégés

Le firewall `^/api` est en `jwt: ~` **sans `access_control` global** : seules les routes portant
`#[IsGranted('ROLE_USER')]` (ou couvertes par `^/api/user`) exigent réellement un token.

| Protégé (401 sans token) | Public (accessible sans token) |
|---|---|
| `GET /api/user/me` | `GET /api/events`, `/api/events/{id}`, `/api/events/search` |
| `GET /api/events/me` | `GET /api/events/category/{categoryId}` |
| `GET /api/organizers/me`, `GET /api/user/me/organizations` | `GET /api/categories`, `/api/locations`, `/api/organizers` |
| `POST /api/organizers` | **`POST /api/events`** ⚠️ |
| tout `/api/organizers/{id}/members…` | `GET /api/admin/events/{id}` ⚠️ |

> ⚠️ La création d'événement et l'endpoint `/api/admin/…` sont ouverts. C'est l'état réel du code,
> pas un oubli de documentation — à ne pas exposer tel quel sur Internet.

## CORS

`config/packages/nelmio_cors.yaml` autorise aujourd'hui, sur `^/api/`, **toutes les origines**
(`allow_origin: ['*']`) avec `allow_credentials: true`, les méthodes `GET, POST, PUT, DELETE, OPTIONS`
et les en-têtes `Content-Type` / `Authorization`. La variable `CORS_ALLOW_ORIGIN` du `.env` **n'est
pas utilisée** par cette configuration.

Conséquences concrètes :

- Un front Angular en `http://localhost:4200` fonctionne sans configuration supplémentaire.
- React Native **n'est pas soumis au CORS** (pas d'origine navigateur) — sauf en cible « web ».
- `PATCH` n'est pas dans `allow_methods` : la mise à jour d'un rôle de membre passe par `PUT`
  depuis un navigateur, ou il faut ajouter `PATCH` à la liste.
- `allow_origin: ['*']` avec `allow_credentials: true` est un couple invalide pour un navigateur.
  Sans importance ici (l'auth passe par un en-tête `Authorization`, pas par un cookie), mais
  **à resserrer avant la mise en production** :

```yaml
# config/packages/nelmio_cors.yaml
nelmio_cors:
    paths:
        '^/api/':
            origin_regex: true
            allow_origin: ['%env(CORS_ALLOW_ORIGIN)%']
            allow_methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
            allow_headers: ['Content-Type', 'Authorization']
            allow_credentials: false
            max_age: 3600
```

```dotenv
# .env.local du serveur de production
CORS_ALLOW_ORIGIN='^https://(www\.)?ticketup\.mg$'
```

---

# Connecter un client externe

Le contrat est le même pour tous les clients :

1. une **URL de base** configurable par environnement ;
2. un **stockage des deux jetons** ;
3. un **intercepteur** qui ajoute `Authorization: Bearer <token>` et rejoue la requête après un
   rafraîchissement, **sérialisé** ;
4. une fonction de **déballage de l'enveloppe** `{ message, status, data }`.

## Front web Angular

Testé avec Angular 17+ (composants standalone, `provideHttpClient`).

### 1. Configuration d'environnement

```ts
// src/environments/environment.ts
export const environment = {
  production: false,
  apiUrl: 'http://localhost:8000/api',
};

// src/environments/environment.prod.ts
export const environment = {
  production: true,
  apiUrl: 'https://api.ticketup.mg/api',
};
```

**Alternative recommandée en développement** : passer par le proxy du dev-server, ce qui supprime
complètement la question du CORS et des cookies inter-origines.

```json
// proxy.conf.json
{ "/api": { "target": "http://localhost:8000", "secure": false, "changeOrigin": true } }
```

```bash
ng serve --proxy-config proxy.conf.json     # apiUrl devient simplement '/api'
```

### 2. Stockage des jetons

```ts
// src/app/core/auth/token.storage.ts
import { Injectable } from '@angular/core';

const ACCESS = 'ticketup.access_token';
const REFRESH = 'ticketup.refresh_token';

@Injectable({ providedIn: 'root' })
export class TokenStorage {
  get access(): string | null { return localStorage.getItem(ACCESS); }
  get refresh(): string | null { return localStorage.getItem(REFRESH); }

  /** Les deux jetons sont renouvelés ensemble : on les écrit toujours ensemble. */
  set(token: string, refreshToken: string): void {
    localStorage.setItem(ACCESS, token);
    localStorage.setItem(REFRESH, refreshToken);
  }

  clear(): void {
    localStorage.removeItem(ACCESS);
    localStorage.removeItem(REFRESH);
  }
}
```

> `localStorage` est lisible par tout script de la page (risque XSS). Pour une application
> sensible, préférer un cookie `HttpOnly` — ce qui suppose de modifier le back (le refresh token
> n'est aujourd'hui renvoyé que dans le corps JSON) et de repasser `allow_credentials: true` avec
> une liste d'origines explicite.

### 3. Service d'authentification

```ts
// src/app/core/auth/auth.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { TokenStorage } from './token.storage';

export interface AuthResponse { token: string; refresh_token: string; }
export interface Me {
  id: number; email: string; firstname: string; lastname: string;
  phone: string | null; language: string | null; roles: string[];
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private storage = inject(TokenStorage);

  private _user = signal<Me | null>(null);
  readonly user = this._user.asReadonly();
  readonly isLoggedIn = computed(() => this._user() !== null);

  login(email: string, password: string): Observable<AuthResponse> {
    // le champ s'appelle "email" (username_path: email dans security.yaml)
    return this.http
      .post<AuthResponse>(`${environment.apiUrl}/auth/login`, { email, password })
      .pipe(tap(r => this.storage.set(r.token, r.refresh_token)));
  }

  /** L'inscription délivre directement les jetons : pas de login à enchaîner. */
  register(payload: {
    email: string; password: string; firstname: string; lastname: string;
    phone?: string; language?: string;
  }): Observable<AuthResponse & { user: Me }> {
    return this.http
      .post<AuthResponse & { user: Me }>(`${environment.apiUrl}/auth/register`, payload)
      .pipe(tap(r => { this.storage.set(r.token, r.refresh_token); this._user.set(r.user); }));
  }

  /** Réponse plate, sans enveloppe. */
  me(): Observable<Me> {
    return this.http
      .get<Me>(`${environment.apiUrl}/user/me`)
      .pipe(tap(u => this._user.set(u)));
  }

  refresh(): Observable<AuthResponse> {
    return this.http
      .post<AuthResponse>(`${environment.apiUrl}/auth/refresh`, {
        refresh_token: this.storage.refresh,
      })
      .pipe(tap(r => this.storage.set(r.token, r.refresh_token)));
  }

  logout(): void {
    this.storage.clear();
    this._user.set(null);
  }
}
```

### 4. Intercepteur : jeton + rafraîchissement sérialisé

C'est la pièce importante. `refreshing$` fait office de file d'attente : la première requête qui
prend un `401` déclenche le refresh, les suivantes attendent le nouveau jeton au lieu de brûler le
refresh token à leur tour.

```ts
// src/app/core/auth/auth.interceptor.ts
import { HttpErrorResponse, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { BehaviorSubject, catchError, filter, switchMap, take, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { TokenStorage } from './token.storage';

let isRefreshing = false;
const refreshed$ = new BehaviorSubject<string | null>(null);

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const storage = inject(TokenStorage);
  const auth = inject(AuthService);
  const router = inject(Router);

  // Les routes d'authentification ne portent pas de jeton et ne doivent jamais être rejouées.
  const isAuthRoute = req.url.includes('/auth/');

  const withToken = (request: HttpRequest<unknown>, token: string | null) =>
    token ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : request;

  return next(isAuthRoute ? req : withToken(req, storage.access)).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status !== 401 || isAuthRoute || !storage.refresh) {
        return throwError(() => error);
      }

      if (isRefreshing) {
        // Un refresh est déjà en vol : on attend son jeton, on ne relance rien.
        return refreshed$.pipe(
          filter((t): t is string => t !== null),
          take(1),
          switchMap(token => next(withToken(req, token))),
        );
      }

      isRefreshing = true;
      refreshed$.next(null);

      return auth.refresh().pipe(
        switchMap(({ token }) => {
          isRefreshing = false;
          refreshed$.next(token);
          return next(withToken(req, token));
        }),
        catchError(err => {
          // Refresh expiré ou déjà consommé : plus rien à tenter.
          isRefreshing = false;
          auth.logout();
          router.navigate(['/login']);
          return throwError(() => err);
        }),
      );
    }),
  );
};
```

```ts
// src/app/app.config.ts
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { authInterceptor } from './core/auth/auth.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideHttpClient(withInterceptors([authInterceptor])),
    // …
  ],
};
```

### 5. Déballer l'enveloppe

```ts
// src/app/core/api/envelope.ts
export interface Envelope<T> { message: string; status: number; data: T; }
export interface Page<T> {
  itemsTotal: number; currentPage: number; nombreParPage: number; items: T[];
}
export const unwrap = <T>() => map((r: Envelope<T>) => r.data);
```

```ts
// src/app/features/events/event.service.ts
@Injectable({ providedIn: 'root' })
export class EventService {
  private http = inject(HttpClient);
  private base = `${environment.apiUrl}/events`;

  list(page = 1, itemsPerPage = 10): Observable<Page<EventListItem>> {
    return this.http
      .get<Envelope<Page<EventListItem>>>(this.base, {
        params: new HttpParams().set('page', page).set('itemsPerPage', itemsPerPage),
      })
      .pipe(unwrap<Page<EventListItem>>());
  }

  /** Événements des organisations dont je suis membre. Nécessite un jeton. */
  mine(page = 1): Observable<Page<EventListItem>> {
    return this.http
      .get<Envelope<Page<EventListItem>>>(`${this.base}/me`, { params: { page } })
      .pipe(unwrap<Page<EventListItem>>());
  }

  byId(id: number): Observable<EventDetails> {
    return this.http
      .get<Envelope<EventDetails>>(`${this.base}/${id}`)
      .pipe(unwrap<EventDetails>());
  }

  /** Au moins un critère est obligatoire, sinon le serveur répond 400. */
  search(criteria: { category?: number; title?: string; startDate?: string; endDate?: string }) {
    let params = new HttpParams();
    Object.entries(criteria).forEach(([k, v]) => {
      if (v !== undefined && v !== '') params = params.set(k, String(v));
    });
    return this.http
      .get<Envelope<Page<EventListItem>>>(`${this.base}/search`, { params })
      .pipe(unwrap<Page<EventListItem>>());
  }

  /**
   * Les ressources sans contrôleur personnalisé répondent en JSON-LD.
   * `Accept: application/json` ramène du JSON simple.
   */
  locations(): Observable<Location[]> {
    return this.http.get<Location[]>(`${environment.apiUrl}/locations`, {
      headers: { Accept: 'application/json' },
    });
  }
}
```

### 6. Garde de route

```ts
// src/app/core/auth/auth.guard.ts
import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { TokenStorage } from './token.storage';

export const authGuard: CanActivateFn = (_route, state) => {
  const router = inject(Router);
  // Présence d'un refresh token = session potentiellement valide ;
  // l'access token, lui, peut très bien être expiré, l'intercepteur s'en charge.
  if (inject(TokenStorage).refresh) return true;
  return router.createUrlTree(['/login'], { queryParams: { redirect: state.url } });
};
```

## Application mobile React Native

Exemples avec `axios` et Expo. Avec le `fetch` natif ou React Query, la logique est identique :
seul le transport change.

```bash
npm install axios @react-native-async-storage/async-storage expo-secure-store
```

### 1. Adresse de l'API selon la cible

`localhost`, dans un émulateur, désigne l'émulateur lui-même — pas votre machine. C'est la cause
n°1 des « Network request failed » au premier lancement.

| Cible | URL de base |
|---|---|
| Simulateur iOS | `http://localhost:8000/api` |
| Émulateur Android (AVD) | `http://10.0.2.2:8000/api` |
| Émulateur Genymotion | `http://10.0.3.2:8000/api` |
| Téléphone physique (même Wi-Fi) | `http://192.168.x.x:8000/api` (IP LAN de la machine) |
| Production | `https://api.ticketup.mg/api` |

Et le serveur doit écouter sur `0.0.0.0` (voir [Lancer le serveur](#lancer-le-serveur)).

```ts
// src/config/api.ts
import { Platform } from 'react-native';
import Constants from 'expo-constants';

const devHost =
  Platform.select({ android: '10.0.2.2', ios: 'localhost', default: 'localhost' });

// Sur téléphone physique via Expo Go, on récupère l'IP LAN du bundler Metro.
const lanHost = Constants.expoConfig?.hostUri?.split(':')[0];

export const API_URL = __DEV__
  ? `http://${lanHost ?? devHost}:8000/api`
  : 'https://api.ticketup.mg/api';
```

> **HTTP en clair.** Android ≥ 9 et iOS bloquent le trafic non chiffré par défaut. En développement,
> avec Expo, ajouter dans `app.json` :
>
> ```json
> {
>   "expo": {
>     "plugins": [["expo-build-properties", {
>       "android": { "usesCleartextTraffic": true },
>       "ios": { "flipperKit": false }
>     }]],
>     "ios": { "infoPlist": { "NSAppTransportSecurity": { "NSAllowsLocalNetworking": true } } }
>   }
> }
> ```
>
> **En production, servir l'API en HTTPS** et ne rien assouplir.

### 2. Stockage sécurisé des jetons

```ts
// src/auth/tokenStorage.ts
import * as SecureStore from 'expo-secure-store';

const ACCESS = 'ticketup.access_token';
const REFRESH = 'ticketup.refresh_token';

export const tokenStorage = {
  getAccess: () => SecureStore.getItemAsync(ACCESS),
  getRefresh: () => SecureStore.getItemAsync(REFRESH),

  async set(token: string, refreshToken: string) {
    // Toujours les deux : /auth/refresh renouvelle aussi le refresh token (single_use).
    await Promise.all([
      SecureStore.setItemAsync(ACCESS, token),
      SecureStore.setItemAsync(REFRESH, refreshToken),
    ]);
  },

  async clear() {
    await Promise.all([
      SecureStore.deleteItemAsync(ACCESS),
      SecureStore.deleteItemAsync(REFRESH),
    ]);
  },
};
```

`expo-secure-store` s'appuie sur le Keychain iOS et le Keystore Android : préférable à
`AsyncStorage`, qui écrit en clair, pour des jetons d'authentification.

### 3. Client axios avec rafraîchissement sérialisé

Même principe que côté Angular : **une seule promesse de refresh partagée** entre toutes les
requêtes en attente, sinon le premier refresh consomme le jeton et les suivants échouent.

```ts
// src/api/client.ts
import axios, { AxiosError, InternalAxiosRequestConfig } from 'axios';
import { API_URL } from '../config/api';
import { tokenStorage } from '../auth/tokenStorage';

export const api = axios.create({
  baseURL: API_URL,
  timeout: 15000,
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

/** Appelé quand la session est définitivement perdue (à brancher sur la navigation). */
let onSessionExpired: () => void = () => {};
export const setOnSessionExpired = (fn: () => void) => { onSessionExpired = fn; };

api.interceptors.request.use(async config => {
  if (!config.url?.includes('/auth/')) {
    const token = await tokenStorage.getAccess();
    if (token) config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Un seul refresh en vol : les requêtes concurrentes attendent la même promesse.
let refreshPromise: Promise<string> | null = null;

async function refreshTokens(): Promise<string> {
  const refreshToken = await tokenStorage.getRefresh();
  if (!refreshToken) throw new Error('no refresh token');

  // Instance nue : surtout pas `api`, sinon l'intercepteur se rappellerait lui-même.
  const { data } = await axios.post<{ token: string; refresh_token: string }>(
    `${API_URL}/auth/refresh`,
    { refresh_token: refreshToken },
    { headers: { 'Content-Type': 'application/json' } },
  );

  await tokenStorage.set(data.token, data.refresh_token);
  return data.token;
}

api.interceptors.response.use(
  response => response,
  async (error: AxiosError) => {
    const original = error.config as InternalAxiosRequestConfig & { _retried?: boolean };

    const refreshable =
      error.response?.status === 401 &&
      original &&
      !original._retried &&
      !original.url?.includes('/auth/');

    if (!refreshable) return Promise.reject(error);

    original._retried = true;

    try {
      refreshPromise = refreshPromise ?? refreshTokens().finally(() => { refreshPromise = null; });
      const token = await refreshPromise;
      original.headers.Authorization = `Bearer ${token}`;
      return api(original);
    } catch (refreshError) {
      await tokenStorage.clear();
      onSessionExpired();
      return Promise.reject(refreshError);
    }
  },
);
```

### 4. Services

```ts
// src/api/auth.ts
import { api } from './client';
import { tokenStorage } from '../auth/tokenStorage';

export async function login(email: string, password: string) {
  const { data } = await api.post('/auth/login', { email, password });
  await tokenStorage.set(data.token, data.refresh_token);
  return data;
}

export async function register(payload: {
  email: string; password: string; firstname: string; lastname: string;
  phone?: string; language?: string;
}) {
  const { data } = await api.post('/auth/register', payload);
  await tokenStorage.set(data.token, data.refresh_token);
  return data; // { token, refresh_token, user }
}

/** Réponse plate, pas d'enveloppe. */
export const me = async () => (await api.get('/user/me')).data;

export async function logout() {
  await tokenStorage.clear();
}
```

```ts
// src/api/events.ts
import { api } from './client';

export interface Page<T> {
  itemsTotal: number; currentPage: number; nombreParPage: number; items: T[];
}

/** Les endpoints à contrôleur renvoient { message, status, data } : on déballe ici. */
const unwrap = <T>(res: { data: { data: T } }): T => res.data.data;

export const listEvents = (page = 1, itemsPerPage = 10) =>
  api.get('/events', { params: { page, itemsPerPage } }).then(unwrap<Page<EventListItem>>);

export const myEvents = (page = 1) =>
  api.get('/events/me', { params: { page } }).then(unwrap<Page<EventListItem>>);

export const eventById = (id: number) =>
  api.get(`/events/${id}`).then(unwrap<EventDetails>);

/** Au moins un critère, sinon 400. */
export const searchEvents = (criteria: Record<string, string | number>) =>
  api.get('/events/search', { params: criteria }).then(unwrap<Page<EventListItem>>);

/** ⚠️ Exception : cet endpoint renvoie un tableau nu, sans enveloppe. */
export const eventsByCategory = (categoryId: number) =>
  api.get(`/events/category/${categoryId}`).then(r => r.data as EventListItem[]);

export const myOrganizations = () =>
  api.get('/user/me/organizations').then(unwrap<Organization[]>);
```

### 5. Restauration de session au démarrage

```tsx
// src/auth/AuthProvider.tsx
import { createContext, useContext, useEffect, useState } from 'react';
import { me } from '../api/auth';
import { tokenStorage } from './tokenStorage';
import { setOnSessionExpired } from '../api/client';

const AuthContext = createContext<{ user: Me | null; ready: boolean }>({ user: null, ready: false });

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<Me | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    setOnSessionExpired(() => setUser(null));

    (async () => {
      // Un access token de 15 min est presque toujours expiré au lancement :
      // on tente quand même /user/me, l'intercepteur rafraîchit tout seul.
      if (await tokenStorage.getRefresh()) {
        try { setUser(await me()); } catch { await tokenStorage.clear(); }
      }
      setReady(true);
    })();
  }, []);

  return <AuthContext.Provider value={{ user, ready }}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
```

## Pièges à connaître côté client

| Piège | Conséquence | Parade |
|---|---|---|
| Refresh token **à usage unique** | Deux refresh concurrents → déconnexion aléatoire | Sérialiser le refresh (voir exemples) |
| Access token de **15 min** | 401 très fréquents, y compris au démarrage de l'app | Intercepteur systématique, jamais de rejeu manuel |
| Champ de login nommé `email` | 401 avec `username` | `{ "email": …, "password": … }` |
| Deux formats de réponse | `data` tantôt enveloppé, tantôt non | Un `unwrap()` central + les exceptions documentées |
| JSON-LD sur certaines routes | `member` au lieu d'un tableau, clés `@id`/`@type` | En-tête `Accept: application/json` |
| `GET /api/events/category/{id}` | Tableau nu, et `[]` (pas `404`) si la catégorie n'existe pas | Cas particulier à traiter |
| `GET /api/events/search` sans critère | `400` | Vérifier qu'au moins un critère est rempli avant l'appel |
| Recherche par titre **sensible à la casse** | « concert » ne trouve pas « Concert » | Prévoir la casse côté saisie en attendant un `ILIKE` côté serveur |
| `GET /api/events` **ne filtre pas** `status` | Les brouillons apparaissent dans la liste publique | Filtrer sur `status` côté client si besoin |
| `itemsPerPage` plafonné (20 / 50) | La valeur demandée peut être ignorée | Lire `nombreParPage` dans la réponse |
| `/api/events/{id}` exige un id **numérique** | `/api/events/abc` → page d'erreur HTML, pas du JSON | Valider l'identifiant avant l'appel |
| `PATCH` absent d'`allow_methods` CORS | Bloqué depuis un navigateur | Utiliser `PUT`, ou ajouter `PATCH` à la config |
| Données de connexion invalides à l'inscription | `500` au lieu de `400`/`422`, email déjà pris compris | Ne pas se fier au code HTTP pour l'affichage du message |

## Documentation complémentaire

| Fichier | Contenu |
|---|---|
| [`API_ENDPOINTS.md`](API_ENDPOINTS.md) | Référence complète des routes, paramètres et réponses |
| [`API_RECHERCHE_EVENTS.md`](API_RECHERCHE_EVENTS.md) | Détail des endpoints de recherche |
| [`STRUCTURE_BDD.md`](STRUCTURE_BDD.md) | Schéma de la base, relations et cascades |
| [`CLAUDE.md`](CLAUDE.md) | Architecture interne et conventions du projet |
| `/api/docs` | OpenAPI / Swagger UI (`php bin/console api:openapi:export` pour l'export) |

## Contribuer

Le travail est intégré sur `develop` via des branches `features/*` ; `main` est la branche de
release. Les commentaires, la documentation et les messages de l'API sont en **français** — merci de
conserver cette convention.
