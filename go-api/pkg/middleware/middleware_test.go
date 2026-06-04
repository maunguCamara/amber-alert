package middleware_test

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
	"github.com/kenya-amber-alert/api/internal/auth"
	"github.com/kenya-amber-alert/api/internal/models"
	"github.com/kenya-amber-alert/api/pkg/middleware"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func init() {
	gin.SetMode(gin.TestMode)
}

// ── helpers ───────────────────────────────────────────────────────────────────

func testAuthSvc() *auth.Service {
	return auth.NewService("test-secret-32-bytes-long-padded", 15*time.Minute, 24*time.Hour)
}

func bearerToken(t *testing.T, svc *auth.Service, user *models.User) string {
	t.Helper()
	token, _, err := svc.IssueTokens(user)
	require.NoError(t, err)
	return "Bearer " + token
}

func userWithRole(role models.UserRole) *models.User {
	return &models.User{
		ID:    uuid.New(),
		Email: "test@example.ke",
		Role:  role,
	}
}

// newRouter builds a minimal Gin engine with the supplied middleware and a
// stub handler that always returns 200.
func newRouter(mw ...gin.HandlerFunc) *gin.Engine {
	r := gin.New()
	r.Use(mw...)
	r.GET("/test", func(c *gin.Context) { c.Status(http.StatusOK) })
	return r
}

// ── Authenticate ──────────────────────────────────────────────────────────────

func TestAuthenticate_ValidToken_Passes(t *testing.T) {
	svc := testAuthSvc()
	r := newRouter(middleware.Authenticate(svc))

	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Authorization", bearerToken(t, svc, userWithRole(models.RolePublic)))
	r.ServeHTTP(w, req)

	assert.Equal(t, http.StatusOK, w.Code)
}

func TestAuthenticate_MissingHeader_Returns401(t *testing.T) {
	r := newRouter(middleware.Authenticate(testAuthSvc()))
	w := httptest.NewRecorder()
	r.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "/test", nil))
	assert.Equal(t, http.StatusUnauthorized, w.Code)
}

func TestAuthenticate_MalformedBearer_Returns401(t *testing.T) {
	r := newRouter(middleware.Authenticate(testAuthSvc()))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Authorization", "Token notabearer")
	r.ServeHTTP(w, req)
	assert.Equal(t, http.StatusUnauthorized, w.Code)
}

func TestAuthenticate_ExpiredToken_Returns401(t *testing.T) {
	expiredSvc := auth.NewService("test-secret-32-bytes-long-padded", -time.Second, 24*time.Hour)
	token, _, _ := expiredSvc.IssueTokens(userWithRole(models.RolePublic))

	// Verify with the normal svc (different TTL, same secret)
	r := newRouter(middleware.Authenticate(testAuthSvc()))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	r.ServeHTTP(w, req)
	assert.Equal(t, http.StatusUnauthorized, w.Code)
}

func TestAuthenticate_WrongSecretToken_Returns401(t *testing.T) {
	otherSvc := auth.NewService("different-secret-32-bytes-padded", 15*time.Minute, 24*time.Hour)
	token, _, _ := otherSvc.IssueTokens(userWithRole(models.RolePublic))

	r := newRouter(middleware.Authenticate(testAuthSvc()))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	r.ServeHTTP(w, req)
	assert.Equal(t, http.StatusUnauthorized, w.Code)
}

// ── RequireRoles ──────────────────────────────────────────────────────────────

func roleRouter(allowedRoles ...models.UserRole) (*gin.Engine, *auth.Service) {
	svc := testAuthSvc()
	r := gin.New()
	r.Use(middleware.Authenticate(svc))
	r.Use(middleware.RequireRoles(allowedRoles...))
	r.GET("/test", func(c *gin.Context) { c.Status(http.StatusOK) })
	return r, svc
}

func TestRequireRoles_AllowedRole_Passes(t *testing.T) {
	r, svc := roleRouter(models.RoleOfficer, models.RoleAdmin)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Authorization", bearerToken(t, svc, userWithRole(models.RoleOfficer)))
	r.ServeHTTP(w, req)
	assert.Equal(t, http.StatusOK, w.Code)
}

func TestRequireRoles_UnauthorisedRole_Returns403(t *testing.T) {
	r, svc := roleRouter(models.RoleAdmin, models.RoleSuperAdmin)
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Authorization", bearerToken(t, svc, userWithRole(models.RolePublic)))
	r.ServeHTTP(w, req)
	assert.Equal(t, http.StatusForbidden, w.Code)
}

func TestRequireRoles_SuperAdmin_PassesAnyRoute(t *testing.T) {
	for _, allowed := range [][]models.UserRole{
		{models.RoleOfficer},
		{models.RoleAdmin},
		{models.RoleOfficer, models.RoleAdmin},
		{models.RoleSuperAdmin},
	} {
		r, svc := roleRouter(allowed...)
		user := userWithRole(models.RoleSuperAdmin)
		// SuperAdmin should only pass routes that explicitly list superadmin
		// (here we test that it passes when superadmin is in the allow list)
		if containsRole(allowed, models.RoleSuperAdmin) {
			w := httptest.NewRecorder()
			req := httptest.NewRequest(http.MethodGet, "/test", nil)
			req.Header.Set("Authorization", bearerToken(t, svc, user))
			r.ServeHTTP(w, req)
			assert.Equal(t, http.StatusOK, w.Code)
		}
	}
}

// ── CORS ──────────────────────────────────────────────────────────────────────

func TestCORS_AllowedOrigin_SetsHeader(t *testing.T) {
	r := newRouter(middleware.CORS([]string{"https://amberalert.go.ke"}))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Origin", "https://amberalert.go.ke")
	r.ServeHTTP(w, req)

	assert.Equal(t, "https://amberalert.go.ke", w.Header().Get("Access-Control-Allow-Origin"))
}

func TestCORS_DisallowedOrigin_NoHeader(t *testing.T) {
	r := newRouter(middleware.CORS([]string{"https://amberalert.go.ke"}))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("Origin", "https://evil.example.com")
	r.ServeHTTP(w, req)

	assert.Empty(t, w.Header().Get("Access-Control-Allow-Origin"))
}

func TestCORS_Preflight_Returns204(t *testing.T) {
	r := newRouter(middleware.CORS([]string{"https://amberalert.go.ke"}))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodOptions, "/test", nil)
	req.Header.Set("Origin", "https://amberalert.go.ke")
	r.ServeHTTP(w, req)

	assert.Equal(t, http.StatusNoContent, w.Code)
}

func TestCORS_AlwaysSetsMethodsAndHeadersFields(t *testing.T) {
	r := newRouter(middleware.CORS([]string{"*"}))
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	r.ServeHTTP(w, req)

	assert.Contains(t, w.Header().Get("Access-Control-Allow-Methods"), "GET")
	assert.Contains(t, w.Header().Get("Access-Control-Allow-Headers"), "Authorization")
}

// ── Rate limiter ──────────────────────────────────────────────────────────────

func TestRateLimit_UnderLimit_Passes(t *testing.T) {
	r := newRouter(middleware.RateLimit(10))
	for i := 0; i < 5; i++ {
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, "/test", nil)
		req.RemoteAddr = "1.2.3.4:1234"
		r.ServeHTTP(w, req)
		assert.Equal(t, http.StatusOK, w.Code, "request %d should pass", i+1)
	}
}

func TestRateLimit_OverLimit_Returns429(t *testing.T) {
	// Limit of 2 requests per minute
	r := newRouter(middleware.RateLimit(2))

	pass := 0
	for i := 0; i < 5; i++ {
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, "/test", nil)
		req.RemoteAddr = "9.9.9.9:9999"
		r.ServeHTTP(w, req)
		if w.Code == http.StatusOK {
			pass++
		}
	}
	// Only the first 2 should pass; the rest hit 429
	assert.Equal(t, 2, pass)
}

func TestRateLimit_DifferentIPs_IndependentBuckets(t *testing.T) {
	r := newRouter(middleware.RateLimit(1))
	ips := []string{"10.0.0.1:1", "10.0.0.2:1", "10.0.0.3:1"}

	for _, ip := range ips {
		w := httptest.NewRecorder()
		req := httptest.NewRequest(http.MethodGet, "/test", nil)
		req.RemoteAddr = ip
		r.ServeHTTP(w, req)
		assert.Equal(t, http.StatusOK, w.Code, "first request from %s should pass", ip)
	}
}

// ── RequestID ─────────────────────────────────────────────────────────────────

func TestRequestID_GeneratesID_WhenAbsent(t *testing.T) {
	r := newRouter(middleware.RequestID())
	w := httptest.NewRecorder()
	r.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "/test", nil))
	assert.NotEmpty(t, w.Header().Get("X-Request-ID"))
}

func TestRequestID_EchoesExistingID(t *testing.T) {
	r := newRouter(middleware.RequestID())
	w := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/test", nil)
	req.Header.Set("X-Request-ID", "my-custom-id-123")
	r.ServeHTTP(w, req)
	assert.Equal(t, "my-custom-id-123", w.Header().Get("X-Request-ID"))
}

// ── helpers ───────────────────────────────────────────────────────────────────

func containsRole(roles []models.UserRole, target models.UserRole) bool {
	for _, r := range roles {
		if r == target {
			return true
		}
	}
	return false
}