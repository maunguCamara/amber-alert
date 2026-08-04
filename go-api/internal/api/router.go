package api

import (
	"github.com/gin-gonic/gin"
	"github.com/jackc/pgx/v5/pgxpool"
	"localhost/amberalert/internal/auth"
	"localhost/amberalert/internal/broadcast"
	"localhost/amberalert/internal/models"
	"localhost/amberalert/internal/repository"
	"localhost/amberalert/internal/websocket"
	"localhost/amberalert/pkg/config"
	"localhost/amberalert/pkg/middleware"
	"github.com/redis/go-redis/v9"
	"go.uber.org/zap"
)

func NewRouter(
	cfg *config.Config,
	db *pgxpool.Pool,
	rdb *redis.Client,
	hub *websocket.Hub,
	storage *repository.Storage,
	log *zap.Logger,
) *gin.Engine {
	if cfg.Environment == "production" {
		gin.SetMode(gin.ReleaseMode)
	}

	r := gin.New()
	r.Use(gin.Recovery())
	r.Use(middleware.RequestID())
	r.Use(middleware.RequestLogger(log))
	r.Use(middleware.CORS(cfg.AllowedOrigins))
	r.Use(middleware.RateLimit(cfg.RateLimitRPM))

	authSvc := auth.NewService(cfg.JWTSecret, cfg.JWTAccessTokenTTL, cfg.JWTRefreshTokenTTL)
	broadcaster := broadcast.NewService(rdb, hub, log)

	// Repos
	caseRepo := repository.NewCaseRepo(db)
	userRepo := repository.NewUserRepo(db)
	mediaRepo := repository.NewMediaRepo(db)
	broadcastRepo := repository.NewBroadcastRepo(db)

	// Handlers
	authH := newAuthHandler(authSvc, userRepo, log)
	caseH := newCaseHandler(caseRepo, mediaRepo, broadcastRepo, broadcaster, storage, cfg, log)
	wsH := newWSHandler(hub, log)
	adminH := newAdminHandler(caseRepo, userRepo, broadcastRepo, log)

	// ─── Public routes ────────────────────────────────────────────────────────
	pub := r.Group("/api/v1")
	{
		// Auth
		pub.POST("/auth/register", authH.Register)
		pub.POST("/auth/login", authH.Login)
		pub.POST("/auth/refresh", authH.Refresh)

		// Public map data — no auth required so the map loads for anyone
		pub.GET("/cases/map", caseH.ListGeoPoints)
		pub.GET("/cases/:id", caseH.GetCase)

		// Africa's Talking SMS/USSD delivery receipt webhook (no auth, uses HMAC)
		pub.POST("/webhooks/at/delivery", caseH.ATDeliveryReceipt)
	}

	// ─── Authenticated routes ─────────────────────────────────────────────────
	authed := r.Group("/api/v1")
	authed.Use(middleware.Authenticate(authSvc))
	{
		// Any logged-in user (public or officer) can submit a case
		authed.POST("/cases", caseH.CreateCase)
		authed.POST("/cases/:id/photos", caseH.UploadPhoto)
		authed.GET("/cases", caseH.ListCases)

		// Officers and above
		officer := authed.Group("/")
		officer.Use(middleware.RequireRoles(
			models.RoleOfficer, models.RoleAdmin, models.RoleSuperAdmin,
		))
		officer.PATCH("/cases/:id/status", caseH.UpdateStatus)
		officer.DELETE("/cases/:id/photos/:photoId", caseH.DeletePhoto)

		// Admin and above
		admin := authed.Group("/admin")
		admin.Use(middleware.RequireRoles(models.RoleAdmin, models.RoleSuperAdmin))
		admin.GET("/cases", adminH.ListAllCases)
		admin.GET("/users", adminH.ListUsers)
		admin.POST("/users", adminH.CreateOfficer)
		admin.PATCH("/users/:id/role", adminH.UpdateUserRole)
		admin.GET("/stats", adminH.Stats)
		admin.POST("/cases/:id/broadcast", adminH.BroadcastCase) // manual SMS blast
	}

	// ─── WebSocket ────────────────────────────────────────────────────────────
	// ws://host/ws?county=Nairobi  (county param is optional)
	r.GET("/ws", wsH.Handle)

	// ─── Health check ─────────────────────────────────────────────────────────
	r.GET("/health", func(c *gin.Context) {
		c.JSON(200, gin.H{"status": "ok", "ws_clients": hub.ConnectedCount()})
	})

	return r
}