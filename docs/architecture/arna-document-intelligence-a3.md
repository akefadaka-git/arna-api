# ARNA Document Intelligence — A3 implementation and worker contract

Status: implementation branch; not deployed.

## Scope

This module processes printed Amharic and English documents from the canonical ARNA `files` table. OCR jobs, results and lifecycle events are tenant-scoped. The original file is never changed. Corrections are append-only records. OCR verification records only the review state of recognized text; it does not approve the source document or authorize a domain transaction.

## API

All endpoints are nested under the existing `auth:sanctum` + `tenant.membership` route group:

- `GET /api/ocr/jobs`
- `POST /api/ocr/jobs`
- `GET /api/ocr/jobs/{id}`
- `GET /api/ocr/jobs/{id}/events`
- `POST /api/ocr/jobs/{id}/retry`
- `GET /api/ocr/results/{id}`
- `GET /api/ocr/search?q=...`
- `POST /api/ocr/results/{id}/verify`
- `POST /api/ocr/results/{id}/corrections`

The trusted tenant comes only from `mases_tenant_id()`. The API additionally scopes every source file, job, result and search query by that tenant. OCR permissions are registered in the canonical permission catalogue. The broad `platform_admin` and `tenant_admin` scope grants explicitly exclude the `ocr.` prefix; `super_admin` retains its wildcard. No OCR role grants are assigned to other roles. Owner approval of a least-privilege reviewer/operator matrix is still required before those users can use the OCR workspace.

## Queue and worker

The worker consumes the dedicated `ocr` queue on the dedicated `ocr` database queue connection. The queue retry window is 1,500 seconds and the job timeout is 1,200 seconds; keep this ordering if either value changes. Jobs are dispatched after the database transaction commits. A row lock claims a queued job so duplicate queue deliveries do not start concurrent recognition for the same job.

Build the worker image from the API repository root:

```sh
docker build -f docker/ocr-worker/Dockerfile -t arna-ocr-worker:<version> .
```

Run it only after the API and worker share the same database, application key, tenant context configuration and canonical file storage access. For local filesystem storage, mount the exact configured file-storage volume read-only into the worker. For object storage, supply the same narrowly scoped read credentials used by the API.

Required runtime configuration:

- `ARNA_OCR_ENABLED=true`
- `ARNA_OCR_QUEUE=ocr`
- `ARNA_OCR_QUEUE_CONNECTION=database`
- `ARNA_OCR_TESSERACT_BINARY=/usr/bin/tesseract`
- `ARNA_OCR_PDFTOPPM_BINARY=/usr/bin/pdftoppm`
- `ARNA_OCR_PDFINFO_BINARY=/usr/bin/pdfinfo`
- `ARNA_OCR_TEMP_DIRECTORY=/tmp/arna-ocr`

The image installs Tesseract, Amharic and English trained data, and Poppler utilities. The worker command is intentionally separate from the web server. Do not expose an HTTP port from this container.

## Operational hardening before deployment

- Run the worker in a dedicated container/host with CPU, memory, PID, disk and execution-time limits.
- Drop Linux capabilities, disable privilege escalation, and use a read-only root filesystem where the Laravel runtime's required writable paths are mounted explicitly.
- Keep the source storage mount read-only; use a private, size-limited temporary filesystem for conversion.
- Run a malware scan before queue submission or before worker processing; the current code does not yet integrate a malware scanner.
- Keep the OCR queue retry_after (1,500 seconds) greater than the job timeout (1,200 seconds).
- Configure worker supervision/restart, failed-job alerting, log redaction and queue-depth monitoring.
- Keep `ARNA_OCR_ENABLED=false` until the worker image and required binaries are installed and validated in the target environment.

## Domain integration boundary

`OcrResultReady` is the cross-domain event contract. Domain consumers may react to a result only after the transaction commits and must re-check the source file's tenant, version and their own domain authorization. OCR output is a draft suggestion; domain-specific approval remains owned by Finance, Governance, Shareholder, Loan, People and other domain authorities.

## Known implementation limitations

- Handwriting is intentionally unsupported.
- Table structure extraction and searchable PDF generation are not implemented in this first A3 slice.
- PDF page rendering depends on Poppler binaries being present in the worker image.
- The worker image has not been built or scanned in this execution.
- No production migration or worker deployment has been performed.
