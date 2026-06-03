package logger

import (
	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
)

type Logger = zap.Logger

func New(env string) *zap.Logger {
	var cfg zap.Config
	if env == "production" {
		cfg = zap.NewProductionConfig()
		cfg.EncoderConfig.TimeKey = "ts"
		cfg.EncoderConfig.EncodeTime = zapcore.ISO8601TimeEncoder
	} else {
		cfg = zap.NewDevelopmentConfig()
		cfg.EncoderConfig.EncodeLevel = zapcore.CapitalColorLevelEncoder
	}

	log, err := cfg.Build()
	if err != nil {
		panic("failed to initialise logger: " + err.Error())
	}
	return log
}

// Convenience field constructors so callers don't import zap directly.

func Error(err error) zap.Field       { return zap.Error(err) }
func String(k, v string) zap.Field    { return zap.String(k, v) }
func Int(k string, v int) zap.Field   { return zap.Int(k, v) }
func Any(k string, v any) zap.Field   { return zap.Any(k, v) }