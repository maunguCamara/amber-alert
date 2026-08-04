package main

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"localhost/amberalert/internal/api"
	"localhost/amberalert/internal/broadcast"
	"localhost/amberalert/internal/repository"
	"localhost/amberalert/internal/websocket"
	"localhost/amberalert/pkg/config"
	"localhost/amberalert/pkg/logger"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintf(os.Stderr, "failed to load config: %v\n", err)
		os.Exit(1)
	}

	log := logger.New(cfg.Environment)
	defer log.Sync() //nolint:errcheck

	// Database pool (PostgreSQL + PostGIS)
	db, err := repository.NewPool(cfg.DatabaseURL)
	if err != nil {
		log.Fatal("failed to connect to postgres", logger.Error(err))
	}
	defer db.Close()

	// Redis client — pub/sub and caching
	rdb, err := repository.NewRedis(cfg.RedisURL)
	if err != nil {
		log.Fatal("failed to connect to redis", logger.Error(err))
	}
	defer rdb.Close()

	// WebSocket hub — manages all connected browser clients
	hub := websocket.NewHub(log)
	go hub.Run()

	// Broadcast service — listens on Redis and fans out to WebSocket hub
	broadcaster := broadcast.NewService(rdb, hub, log)
	go broadcaster.Listen(context.Background())

	// S3-compatible storage (MinIO in dev, AWS S3 in prod)
	storage, err := repository.NewStorage(cfg)
	if err != nil {
		log.Fatal("failed to init object storage", logger.Error(err))
	}

	router := api.NewRouter(cfg, db, rdb, hub, storage, log)

	srv := &http.Server{
		Addr:         fmt.Sprintf(":%d", cfg.Port),
		Handler:      router,
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 15 * time.Second,
		IdleTimeout:  60 * time.Second,
	}

	go func() {
		log.Info(fmt.Sprintf("Kenya Amber Alert API listening on :%d [%s]", cfg.Port, cfg.Environment))
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal("server error", logger.Error(err))
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	log.Info("shutting down server...")
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		log.Fatal("forced shutdown", logger.Error(err))
	}
	log.Info("server stopped cleanly")
}