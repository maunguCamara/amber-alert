AMBER_ENVIRONMENT=development
AMBER_PORT=8080
AMBER_DATABASE_URL="postgres://angt-whiz:amber123@localhost:5432/amber_alert_dev?sslmode=disable"
AMBER_REDIS_URL="redis://127.0.0.1:6379/0"
AMBER_JWT_SECRET="dev-secret-key-32-chars-minimum!!"
AMBER_JWT_ACCESS_TOKEN_TTL=15m
AMBER_JWT_REFRESH_TOKEN_TTL=168h
# Local filesystem storage (no MinIO needed for dev)
AMBER_S3_ENDPOINT=
AMBER_S3_BUCKET=amber-alert-dev
AMBER_S3_ACCESS_KEY=dev
AMBER_S3_SECRET_KEY=dev
AMBER_S3_REGION=us-east-1
AMBER_S3_FORCE_PATH_STYLE=true
# Africa's Talking sandbox (get free sandbox key at africastalking.com)
AMBER_AT_API_KEY=sandbox_key
AMBER_AT_USERNAME=sandbox
AMBER_AT_SHORT_CODE=22384
AMBER_CLUSTER_SERVICE_ADDR=localhost:50051
AMBER_ALLOWED_ORIGINS=http://localhost:8000,http://localhost:8080
AMBER_RATE_LIMIT_RPM=300
