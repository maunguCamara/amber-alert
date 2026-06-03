package broadcast

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/kenya-amber-alert/api/internal/models"
	"github.com/kenya-amber-alert/api/internal/repository"
	"github.com/kenya-amber-alert/api/internal/websocket"
	"github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

// Service subscribes to Redis pub/sub channels and fans alert events
// out to all connected WebSocket clients and the SMS broadcast queue.
type Service struct {
	rdb *redis.Client
	hub *websocket.Hub
	log *zap.Logger
}

func NewService(rdb *redis.Client, hub *websocket.Hub, log *zap.Logger) *Service {
	return &Service{rdb: rdb, hub: hub, log: log}
}

// Listen blocks; run it in a goroutine.
func (s *Service) Listen(ctx context.Context) {
	channels := []string{
		repository.ChanCaseNew,
		repository.ChanCaseUpdated,
		repository.ChanCaseResolved,
	}

	pubsub := s.rdb.Subscribe(ctx, channels...)
	defer pubsub.Close()

	s.log.Info("broadcast service listening", zap.Strings("channels", channels))

	for {
		select {
		case <-ctx.Done():
			return
		case msg, ok := <-pubsub.Channel():
			if !ok {
				s.log.Warn("redis pubsub channel closed, reconnecting in 5s")
				time.Sleep(5 * time.Second)
				pubsub = s.rdb.Subscribe(ctx, channels...)
				continue
			}
			s.handleMessage(msg)
		}
	}
}

func (s *Service) handleMessage(msg *redis.Message) {
	eventType := channelToEventType(msg.Channel)

	var point models.CaseGeoPoint
	if err := json.Unmarshal([]byte(msg.Payload), &point); err != nil {
		s.log.Error("broadcast: failed to unmarshal payload",
			zap.String("channel", msg.Channel), zap.Error(err))
		return
	}

	event := models.WSEvent{
		Type:    eventType,
		Payload: point,
	}

	// Fan out to all connected browser clients (county-filtered inside hub).
	s.hub.Broadcast(event, point.County)

	s.log.Info("broadcast dispatched",
		zap.String("event", eventType),
		zap.String("case", point.ReferenceNo),
		zap.Int("ws_clients", s.hub.ConnectedCount()),
	)
}

// Publish is called by the case service after a write to PostgreSQL.
func (s *Service) Publish(ctx context.Context, channel string, point *models.CaseGeoPoint) error {
	payload, err := json.Marshal(point)
	if err != nil {
		return fmt.Errorf("marshal case geo point: %w", err)
	}
	return s.rdb.Publish(ctx, channel, payload).Err()
}

func channelToEventType(ch string) string {
	switch ch {
	case repository.ChanCaseNew:
		return "case.new"
	case repository.ChanCaseUpdated:
		return "case.updated"
	case repository.ChanCaseResolved:
		return "case.resolved"
	default:
		return "case.event"
	}
}