package repository

import (
	"context"
	"fmt"

	"github.com/redis/go-redis/v9"
)

const (
	ChanCaseNew      = "amber:case:new"
	ChanCaseUpdated  = "amber:case:updated"
	ChanCaseResolved = "amber:case:resolved"
	ChanBroadcastSMS = "amber:broadcast:sms"
)

func NewRedis(url string) (*redis.Client, error) {
	opt, err := redis.ParseURL(url)
	if err != nil {
		return nil, fmt.Errorf("parse redis url: %w", err)
	}

	opt.PoolSize = 20
	opt.MinIdleConns = 5

	rdb := redis.NewClient(opt)
	if err := rdb.Ping(context.Background()).Err(); err != nil {
		return nil, fmt.Errorf("ping redis: %w", err)
	}
	return rdb, nil
}