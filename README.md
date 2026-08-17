# Image API

A private API for uploading and storing images. It accepts PNG and JPEG up to
5 MB, puts them into object storage, compresses them in the background, and
serves them to the owner — and only to the owner.

**Stack:** Laravel 13 · PHP 8.4 · PostgreSQL 17 · Redis · MinIO (S3) · Sanctum ·
Intervention Image

---

## Running it

```bash
docker compose up -d
```

Nothing else to do: the container installs the dependencies, generates the
`APP_KEY` and runs the migrations on its own.

| | |
|---|---|
| API | http://localhost:8080/api/v1 |
| Health check | http://localhost:8080/up |
| MinIO console | http://localhost:9001 (`minioadmin` / `minioadmin`) |
| PostgreSQL | `localhost:55432`, database `dmed`, user `dmed` / `secret` |

Seven services come up: nginx, php-fpm, the queue worker, the scheduler,
PostgreSQL, Redis and MinIO. Compression runs in a separate worker, so for a
short while after the upload the image sits in `pending` status — during that
window the original is served.

### Trying it out

Import `postman/image-api.postman_collection.json` into Postman. It covers the
whole flow and is meant to be run top to bottom: Register (or Login) stores the
token and injects it into every following request, Upload image stores the
returned identifier, and the rest of the requests work off it — the only manual
step is picking a file for the `image` field.

Alongside the happy path there is a **Limit checks** folder: no token, another
user's image, a non-image file and one over 5 MB. `base_url` points at the local
docker stack by default.

### Background commands

The `scheduler` container runs these; they are listed here because they are
where the storage guarantees actually live:

| Command | Cleans up |
|---|---|
| `images:prune` | blobs whose delayed cleanup job never made it through the queue |
| `images:sweep --prefix=ab` | files on disk that no row points at |
| `sanctum:prune-expired` | expired tokens (they live for a week) |

### Tests

```bash
docker compose exec app php artisan test
```

55 tests: type and size validation, user isolation, deduplication with
reference counting, compression, deletion and garbage collection.

### Without Docker

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan serve
```

That defaults to SQLite, the local disk and a synchronous queue — compression
happens inside the request.

## Architecture

```
app/
├── Domain/                     business logic, knows nothing about HTTP
│   ├── Images/
│   │   ├── Actions/            StoreUploadedImage, ReleaseImage
│   │   ├── Console/            images:prune, images:sweep
│   │   ├── Data/               DTOs
│   │   ├── Jobs/               compression and garbage collection
│   │   ├── Models/             Image, ImageBlob
│   │   ├── Policies/           ownership checks
│   │   ├── Rules/              file content validation
│   │   ├── Services/           ImageCompressor
│   │   └── Support/            storage path layout
│   └── Users/Models/
└── Http/                       transport layer
    ├── Controllers/Api/V1/
    ├── Middleware/
    ├── Requests/Api/V1/
    └── Resources/Api/V1/

routes/api/v1/{auth,images}.php  one file per resource
```

The controller only does HTTP: it parses the request, calls the action and
wraps the result in a resource. Everything about storage, reference counts and
the queue lives in the domain and does not care whether the request arrived
over HTTP or from the console.

### Data model

Ownership and bytes live in separate tables — that split is what makes
deduplication possible:

```
images                          image_blobs
──────────────────────          ──────────────────────
ulid        public id           hash        sha256, UNIQUE
user_id                    ┌──► id
image_blob_id ─────────────┘    path        {ab}/{cd}/{hash}.webp
original_name                   references  how many records point at it
UNIQUE (user_id, image_blob_id) status      pending | ready | failed
INDEX  (user_id, id)
```
