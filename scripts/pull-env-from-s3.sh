#!/usr/bin/env bash
#
# Pulls the production .env from S3 using the IAM role attached to this EC2
# instance. Run BEFORE `docker compose up`. Idempotent — safe to re-run on
# every deploy to pick up rotated secrets.
#
# Requires:
#   - AWS CLI v2 installed
#   - IAM role on this EC2 with s3:GetObject for the bucket/path below
#
# Override via env vars when needed (e.g. promoting staging → prod):
#   S3_ENV_BUCKET=anka-secrets-prod ./scripts/pull-env-from-s3.sh
#   S3_ENV_KEY=anka-api/staging/.env ./scripts/pull-env-from-s3.sh

set -euo pipefail

# ── Configurable ─────────────────────────────────────────────────────────────
: "${S3_ENV_BUCKET:=anka-secrets-prod}"            # change to your bucket
: "${S3_ENV_KEY:=anka-api/.env}"                   # change to your object key
: "${LOCAL_ENV_PATH:=.env}"                        # where to write it
: "${AWS_REGION:=ap-southeast-1}"                  # change to your region

# ── Guards ───────────────────────────────────────────────────────────────────
if ! command -v aws >/dev/null 2>&1; then
    echo "FATAL: aws CLI not found. Install with:" >&2
    echo "  curl 'https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip' -o awscliv2.zip" >&2
    echo "  unzip awscliv2.zip && sudo ./aws/install" >&2
    exit 1
fi

# Confirm the EC2 instance role works before touching S3.
if ! aws sts get-caller-identity --region "$AWS_REGION" >/dev/null 2>&1; then
    echo "FATAL: aws sts get-caller-identity failed." >&2
    echo "       Is an IAM role attached to this EC2 instance? Check:" >&2
    echo "       EC2 console → Instance → Security → IAM role" >&2
    exit 1
fi

# ── Backup existing .env if present ──────────────────────────────────────────
if [[ -f "$LOCAL_ENV_PATH" ]]; then
    backup="${LOCAL_ENV_PATH}.bak.$(date +%Y%m%d-%H%M%S)"
    cp "$LOCAL_ENV_PATH" "$backup"
    echo "Backed up existing .env → $backup"
fi

# ── Pull ─────────────────────────────────────────────────────────────────────
echo "Downloading s3://${S3_ENV_BUCKET}/${S3_ENV_KEY} → ${LOCAL_ENV_PATH}"
aws s3 cp \
    "s3://${S3_ENV_BUCKET}/${S3_ENV_KEY}" \
    "$LOCAL_ENV_PATH" \
    --region "$AWS_REGION"

# Lock down perms — only the current user should read .env.
chmod 600 "$LOCAL_ENV_PATH"

# ── Sanity check ─────────────────────────────────────────────────────────────
required_keys=(APP_KEY APP_URL DB_HOST DB_PASSWORD ANTHROPIC_API_KEY)
missing=()
for key in "${required_keys[@]}"; do
    if ! grep -qE "^${key}=." "$LOCAL_ENV_PATH"; then
        missing+=("$key")
    fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
    echo "WARNING: the downloaded .env is missing values for:" >&2
    printf '  - %s\n' "${missing[@]}" >&2
    echo "Either the upload was incomplete, or these were left as TODOs." >&2
    exit 1
fi

echo "✓ .env downloaded ($(wc -l < "$LOCAL_ENV_PATH") lines, $(stat -c%s "$LOCAL_ENV_PATH") bytes)"
echo "✓ Required keys present"
echo ""
echo "Next: docker compose build && docker compose up -d"
