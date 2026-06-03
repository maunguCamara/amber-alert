package config

import (
	"fmt"
	"strings"
	"time"

	"github.com/spf13/viper"
)

type Config struct {
	Environment string
	Port        int

	DatabaseURL string

	RedisURL string

	// JWT
	JWTSecret          string
	JWTAccessTokenTTL  time.Duration
	JWTRefreshTokenTTL time.Duration

	// S3 / MinIO
	S3Endpoint        string
	S3Bucket          string
	S3AccessKey       string
	S3SecretKey       string
	S3Region          string
	S3ForcePathStyle  bool

	// Africa's Talking (SMS)
	ATApiKey    string
	ATUsername  string
	ATShortCode string

	// Clustering microservice (Rust gRPC)
	ClusterServiceAddr string

	// CORS
	AllowedOrigins []string

	// Rate limiting
	RateLimitRPM int

	// Max upload size in bytes
	MaxUploadBytes int64
}

func Load() (*Config, error) {
	v := viper.New()

	v.SetDefault("environment", "development")
	v.SetDefault("port", 8080)
	v.SetDefault("jwt_access_token_ttl", "15m")
	v.SetDefault("jwt_refresh_token_ttl", "168h")
	v.SetDefault("s3_region", "us-east-1")
	v.SetDefault("s3_force_path_style", true)
	v.SetDefault("cluster_service_addr", "localhost:50051")
	v.SetDefault("rate_limit_rpm", 60)
	v.SetDefault("max_upload_bytes", 10_485_760) // 10 MB
	v.SetDefault("allowed_origins", []string{"http://localhost:3000", "https://amberalert.go.ke"})

	v.SetConfigName("config")
	v.SetConfigType("yaml")
	v.AddConfigPath(".")
	v.AddConfigPath("/etc/amber-alert/")

	v.SetEnvPrefix("AMBER")
	v.SetEnvKeyReplacer(strings.NewReplacer(".", "_"))
	v.AutomaticEnv()

	// Non-fatal if config file is missing — env vars take precedence
	_ = v.ReadInConfig()

	required := []string{"database_url", "redis_url", "jwt_secret"}
	for _, key := range required {
		if v.GetString(key) == "" {
			return nil, fmt.Errorf("required config key %q is not set", key)
		}
	}

	accessTTL, err := time.ParseDuration(v.GetString("jwt_access_token_ttl"))
	if err != nil {
		return nil, fmt.Errorf("invalid jwt_access_token_ttl: %w", err)
	}
	refreshTTL, err := time.ParseDuration(v.GetString("jwt_refresh_token_ttl"))
	if err != nil {
		return nil, fmt.Errorf("invalid jwt_refresh_token_ttl: %w", err)
	}

	return &Config{
		Environment:        v.GetString("environment"),
		Port:               v.GetInt("port"),
		DatabaseURL:        v.GetString("database_url"),
		RedisURL:           v.GetString("redis_url"),
		JWTSecret:          v.GetString("jwt_secret"),
		JWTAccessTokenTTL:  accessTTL,
		JWTRefreshTokenTTL: refreshTTL,
		S3Endpoint:         v.GetString("s3_endpoint"),
		S3Bucket:           v.GetString("s3_bucket"),
		S3AccessKey:        v.GetString("s3_access_key"),
		S3SecretKey:        v.GetString("s3_secret_key"),
		S3Region:           v.GetString("s3_region"),
		S3ForcePathStyle:   v.GetBool("s3_force_path_style"),
		ATApiKey:           v.GetString("at_api_key"),
		ATUsername:         v.GetString("at_username"),
		ATShortCode:        v.GetString("at_short_code"),
		ClusterServiceAddr: v.GetString("cluster_service_addr"),
		AllowedOrigins:     v.GetStringSlice("allowed_origins"),
		RateLimitRPM:       v.GetInt("rate_limit_rpm"),
		MaxUploadBytes:     v.GetInt64("max_upload_bytes"),
	}, nil
}