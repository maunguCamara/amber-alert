package config_test

import (
	"os"
	"testing"

	"github.com/kenya-amber-alert/api/pkg/config"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func setEnv(t *testing.T, kv map[string]string) {
	t.Helper()
	for k, v := range kv {
		t.Setenv("AMBER_"+k, v)
	}
}

func requiredEnv(t *testing.T) map[string]string {
	return map[string]string{
		"DATABASE_URL": "postgres://amber:pass@localhost:5432/amber_test?sslmode=disable",
		"REDIS_URL":    "redis://:pass@localhost:6379/0",
		"JWT_SECRET":   "test-secret-that-is-long-enough-32!",
	}
}

// ── Load ─────────────────────────────────────────────────────────────────────

func TestLoad_WithRequiredVars_Succeeds(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.NotNil(t, cfg)
}

func TestLoad_MissingDatabaseURL_ReturnsError(t *testing.T) {
	setEnv(t, map[string]string{
		"REDIS_URL":  "redis://localhost:6379/0",
		"JWT_SECRET": "test-secret-long-enough-padding!!!",
	})
	// Ensure DATABASE_URL is not set
	os.Unsetenv("AMBER_DATABASE_URL") //nolint:errcheck
	_, err := config.Load()
	require.Error(t, err)
	assert.Contains(t, err.Error(), "database_url")
}

func TestLoad_MissingRedisURL_ReturnsError(t *testing.T) {
	setEnv(t, map[string]string{
		"DATABASE_URL": "postgres://amber:pass@localhost:5432/amber?sslmode=disable",
		"JWT_SECRET":   "test-secret-long-enough-padding!!!",
	})
	os.Unsetenv("AMBER_REDIS_URL") //nolint:errcheck
	_, err := config.Load()
	require.Error(t, err)
	assert.Contains(t, err.Error(), "redis_url")
}

func TestLoad_MissingJWTSecret_ReturnsError(t *testing.T) {
	setEnv(t, map[string]string{
		"DATABASE_URL": "postgres://amber:pass@localhost:5432/amber?sslmode=disable",
		"REDIS_URL":    "redis://localhost:6379/0",
	})
	os.Unsetenv("AMBER_JWT_SECRET") //nolint:errcheck
	_, err := config.Load()
	require.Error(t, err)
	assert.Contains(t, err.Error(), "jwt_secret")
}

// ── Defaults ─────────────────────────────────────────────────────────────────

func TestLoad_DefaultPort_Is8080(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, 8080, cfg.Port)
}

func TestLoad_DefaultEnvironment_IsDevelopment(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, "development", cfg.Environment)
}

func TestLoad_DefaultRateLimitRPM_Is60(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, 60, cfg.RateLimitRPM)
}

func TestLoad_DefaultMaxUploadBytes_Is10MB(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, int64(10_485_760), cfg.MaxUploadBytes)
}

func TestLoad_DefaultClusterAddr(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, "localhost:50051", cfg.ClusterServiceAddr)
}

// ── Override via env ──────────────────────────────────────────────────────────

func TestLoad_PortOverride(t *testing.T) {
	env := requiredEnv(t)
	env["PORT"] = "9090"
	setEnv(t, env)
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, 9090, cfg.Port)
}

func TestLoad_EnvironmentOverride(t *testing.T) {
	env := requiredEnv(t)
	env["ENVIRONMENT"] = "production"
	setEnv(t, env)
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, "production", cfg.Environment)
}

func TestLoad_InvalidAccessTokenTTL_ReturnsError(t *testing.T) {
	env := requiredEnv(t)
	env["JWT_ACCESS_TOKEN_TTL"] = "not-a-duration"
	setEnv(t, env)
	_, err := config.Load()
	require.Error(t, err)
	assert.Contains(t, err.Error(), "jwt_access_token_ttl")
}

func TestLoad_TTLs_Parsed(t *testing.T) {
	env := requiredEnv(t)
	env["JWT_ACCESS_TOKEN_TTL"]  = "30m"
	env["JWT_REFRESH_TOKEN_TTL"] = "720h"
	setEnv(t, env)
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.Equal(t, "30m0s",  cfg.JWTAccessTokenTTL.String())
	assert.Equal(t, "720h0m0s", cfg.JWTRefreshTokenTTL.String())
}

func TestLoad_S3ForcePathStyle_Default(t *testing.T) {
	setEnv(t, requiredEnv(t))
	cfg, err := config.Load()
	require.NoError(t, err)
	assert.True(t, cfg.S3ForcePathStyle)
}