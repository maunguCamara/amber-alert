package middleware

import (
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
	"localhost/amberalert/internal/auth"
	"localhost/amberalert/internal/models"
	"go.uber.org/zap"
)

const claimsKey = "claims"

// ─── JWT Authentication ───────────────────────────────────────────────────────

// Authenticate extracts and validates the Bearer token; aborts on failure.
func Authenticate(authSvc *auth.Service) gin.HandlerFunc {
	return func(c *gin.Context) {
		header := c.GetHeader("Authorization")
		if header == "" || !strings.HasPrefix(header, "Bearer ") {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "missing or invalid authorization header"})
			return
		}
		tokenStr := strings.TrimPrefix(header, "Bearer ")
		claims, err := authSvc.VerifyAccess(tokenStr)
		if err != nil {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "invalid or expired token"})
			return
		}
		c.Set(claimsKey, claims)
		c.Next()
	}
}

// RequireRoles aborts if the authenticated user's role isn't in the allowed set.
func RequireRoles(roles ...models.UserRole) gin.HandlerFunc {
	allowed := make(map[models.UserRole]struct{}, len(roles))
	for _, r := range roles {
		allowed[r] = struct{}{}
	}
	return func(c *gin.Context) {
		claims := MustClaims(c)
		if _, ok := allowed[claims.Role]; !ok {
			c.AbortWithStatusJSON(http.StatusForbidden, gin.H{"error": "insufficient permissions"})
			return
		}
		c.Next()
	}
}

// MustClaims retrieves the JWT claims set by Authenticate; panics if missing.
func MustClaims(c *gin.Context) *auth.Claims {
	v, _ := c.Get(claimsKey)
	return v.(*auth.Claims)
}

// CurrentUserID is a convenience helper for handlers.
func CurrentUserID(c *gin.Context) uuid.UUID {
	return MustClaims(c).UserID
}

// ─── CORS ─────────────────────────────────────────────────────────────────────

func CORS(allowedOrigins []string) gin.HandlerFunc {
	originSet := make(map[string]struct{}, len(allowedOrigins))
	for _, o := range allowedOrigins {
		originSet[o] = struct{}{}
	}

	return func(c *gin.Context) {
		origin := c.GetHeader("Origin")
		if _, ok := originSet[origin]; ok {
			c.Header("Access-Control-Allow-Origin", origin)
			c.Header("Vary", "Origin")
		}
		c.Header("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
		c.Header("Access-Control-Allow-Headers", "Authorization, Content-Type, X-Request-ID")
		c.Header("Access-Control-Max-Age", "86400")

		if c.Request.Method == http.MethodOptions {
			c.AbortWithStatus(http.StatusNoContent)
			return
		}
		c.Next()
	}
}

// ─── Rate limiter (per-IP, token-bucket style) ────────────────────────────────

type bucket struct {
	tokens    float64
	lastRefil time.Time
}

type rateLimiter struct {
	mu      sync.Mutex
	buckets map[string]*bucket
	rpm     float64
}

func RateLimit(requestsPerMinute int) gin.HandlerFunc {
	rl := &rateLimiter{
		buckets: make(map[string]*bucket),
		rpm:     float64(requestsPerMinute),
	}

	// Periodic cleanup to prevent unbounded map growth.
	go func() {
		for range time.Tick(5 * time.Minute) {
			rl.mu.Lock()
			cutoff := time.Now().Add(-10 * time.Minute)
			for ip, b := range rl.buckets {
				if b.lastRefil.Before(cutoff) {
					delete(rl.buckets, ip)
				}
			}
			rl.mu.Unlock()
		}
	}()

	return func(c *gin.Context) {
		ip := c.ClientIP()

		rl.mu.Lock()
		b, ok := rl.buckets[ip]
		if !ok {
			b = &bucket{tokens: rl.rpm, lastRefil: time.Now()}
			rl.buckets[ip] = b
		}

		// Refill tokens proportional to time elapsed.
		elapsed := time.Since(b.lastRefil).Minutes()
		b.tokens = min(rl.rpm, b.tokens+elapsed*rl.rpm)
		b.lastRefil = time.Now()

		if b.tokens < 1 {
			rl.mu.Unlock()
			c.Header("Retry-After", "60")
			c.AbortWithStatusJSON(http.StatusTooManyRequests, gin.H{"error": "rate limit exceeded"})
			return
		}
		b.tokens--
		rl.mu.Unlock()

		c.Next()
	}
}

// ─── Request Logger ───────────────────────────────────────────────────────────

func RequestLogger(log *zap.Logger) gin.HandlerFunc {
	return func(c *gin.Context) {
		start := time.Now()
		c.Next()
		log.Info("http",
			zap.String("method", c.Request.Method),
			zap.String("path", c.Request.URL.Path),
			zap.Int("status", c.Writer.Status()),
			zap.Duration("latency", time.Since(start)),
			zap.String("ip", c.ClientIP()),
		)
	}
}

// ─── Request ID ───────────────────────────────────────────────────────────────

func RequestID() gin.HandlerFunc {
	return func(c *gin.Context) {
		id := c.GetHeader("X-Request-ID")
		if id == "" {
			id = uuid.NewString()
		}
		c.Set("request_id", id)
		c.Header("X-Request-ID", id)
		c.Next()
	}
}

func min(a, b float64) float64 {
	if a < b {
		return a
	}
	return b
}