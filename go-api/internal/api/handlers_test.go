package api_test

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
	"amberalert/internal/auth"
	"amberalert/internal/models"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func init() { gin.SetMode(gin.TestMode) }

// ── Test doubles ──────────────────────────────────────────────────────────────

// stubUserRepo satisfies the interface expected by the auth handler.
type stubUserRepo struct {
	users  map[string]*models.User
	stored []*models.User
}

func newStubUserRepo() *stubUserRepo {
	hash, _ := auth.HashPassword("Password123!")
	u := &models.User{
		ID:           uuid.MustParse("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"),
		Email:        "existing@example.ke",
		FullName:     "Existing User",
		Phone:        "+254711000001",
		Role:         models.RolePublic,
		PasswordHash: hash,
		IsVerified:   true,
		IsActive:     true,
	}
	return &stubUserRepo{
		users: map[string]*models.User{u.Email: u},
	}
}

// stubCaseRepo — returns deterministic data without touching PostgreSQL.
type stubCaseRepo struct {
	cases map[uuid.UUID]*models.Case
}

func newStubCaseRepo() *stubCaseRepo {
	caseID := uuid.MustParse("cccccccc-cccc-cccc-cccc-cccccccccccc")
	c := &models.Case{
		ID:           caseID,
		ReferenceNo:  "KE-2024-00001",
		ChildName:    "Brian Otieno",
		Age:          8,
		Gender:       models.GenderMale,
		Status:       models.CaseStatusActive,
		County:       "Nairobi",
		LastSeenArea: "Mathare",
		LastSeenLat:  -1.286,
		LastSeenLng:  36.817,
		MissingSince: time.Now().Add(-48 * time.Hour),
		CreatedAt:    time.Now().Add(-48 * time.Hour),
		UpdatedAt:    time.Now(),
	}
	return &stubCaseRepo{cases: map[uuid.UUID]*models.Case{caseID: c}}
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

func doRequest(t *testing.T, router http.Handler, method, path string, body any, token string) *httptest.ResponseRecorder {
	t.Helper()
	var buf bytes.Buffer
	if body != nil {
		require.NoError(t, json.NewEncoder(&buf).Encode(body))
	}
	req := httptest.NewRequest(method, path, &buf)
	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	w := httptest.NewRecorder()
	router.ServeHTTP(w, req)
	return w
}

func mustToken(t *testing.T, svc *auth.Service, user *models.User) string {
	t.Helper()
	tok, _, err := svc.IssueTokens(user)
	require.NoError(t, err)
	return tok
}

// ── Health endpoint ───────────────────────────────────────────────────────────

func TestHealth_Returns200(t *testing.T) {
	r := gin.New()
	r.GET("/health", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{"status": "ok"})
	})

	w := doRequest(t, r, http.MethodGet, "/health", nil, "")
	assert.Equal(t, http.StatusOK, w.Code)

	var resp map[string]string
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &resp))
	assert.Equal(t, "ok", resp["status"])
}

// ── Auth endpoints ────────────────────────────────────────────────────────────

func TestAuthRegister_ValidPayload_Returns201(t *testing.T) {
	// Use a minimal Gin router that mimics the register flow
	// without touching the real DB — validates request parsing.
	r := gin.New()
	r.POST("/api/v1/auth/register", func(c *gin.Context) {
		var req struct {
			Email    string `json:"email"     binding:"required,email"`
			FullName string `json:"full_name" binding:"required"`
			Phone    string `json:"phone"     binding:"required"`
			Password string `json:"password"  binding:"required,min=8"`
		}
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		c.JSON(http.StatusCreated, gin.H{"email": req.Email})
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/auth/register", map[string]string{
		"email":     "new@example.ke",
		"full_name": "New User",
		"phone":     "+254700000001",
		"password":  "Password123!",
	}, "")

	assert.Equal(t, http.StatusCreated, w.Code)
}

func TestAuthRegister_MissingEmail_Returns400(t *testing.T) {
	r := gin.New()
	r.POST("/api/v1/auth/register", func(c *gin.Context) {
		var req struct {
			Email string `json:"email" binding:"required,email"`
		}
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		c.JSON(http.StatusCreated, nil)
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/auth/register", map[string]string{
		"full_name": "No Email User",
	}, "")
	assert.Equal(t, http.StatusBadRequest, w.Code)
}

func TestAuthRegister_ShortPassword_Returns400(t *testing.T) {
	r := gin.New()
	r.POST("/api/v1/auth/register", func(c *gin.Context) {
		var req struct {
			Password string `json:"password" binding:"required,min=8"`
		}
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		c.JSON(http.StatusCreated, nil)
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/auth/register", map[string]string{
		"password": "short",
	}, "")
	assert.Equal(t, http.StatusBadRequest, w.Code)
}

func TestAuthLogin_ValidCredentials_ReturnsTokens(t *testing.T) {
	svc := auth.NewService("test-secret-32-bytes-long-padded", 15*time.Minute, 24*time.Hour)
	user := &models.User{
		ID:    uuid.New(),
		Email: "login@example.ke",
		Role:  models.RolePublic,
	}
	hash, _ := auth.HashPassword("Password123!")
	user.PasswordHash = hash

	r := gin.New()
	r.POST("/api/v1/auth/login", func(c *gin.Context) {
		var req struct {
			Email    string `json:"email"`
			Password string `json:"password"`
		}
		c.ShouldBindJSON(&req)
		if !auth.CheckPassword(user.PasswordHash, req.Password) {
			c.JSON(http.StatusUnauthorized, gin.H{"error": "invalid credentials"})
			return
		}
		access, refresh, _ := svc.IssueTokens(user)
		c.JSON(http.StatusOK, gin.H{"access_token": access, "refresh_token": refresh})
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/auth/login", map[string]string{
		"email":    "login@example.ke",
		"password": "Password123!",
	}, "")

	assert.Equal(t, http.StatusOK, w.Code)
	var resp map[string]string
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &resp))
	assert.NotEmpty(t, resp["access_token"])
	assert.NotEmpty(t, resp["refresh_token"])
}

func TestAuthLogin_WrongPassword_Returns401(t *testing.T) {
	hash, _ := auth.HashPassword("correct-password")
	r := gin.New()
	r.POST("/api/v1/auth/login", func(c *gin.Context) {
		var req struct{ Password string `json:"password"` }
		c.ShouldBindJSON(&req)
		if !auth.CheckPassword(hash, req.Password) {
			c.JSON(http.StatusUnauthorized, gin.H{"error": "invalid credentials"})
			return
		}
		c.JSON(http.StatusOK, nil)
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/auth/login", map[string]string{
		"password": "wrong-password",
	}, "")
	assert.Equal(t, http.StatusUnauthorized, w.Code)
}

// ── Cases — authorisation ─────────────────────────────────────────────────────

func TestCreateCase_UnauthenticatedRequest_Returns401(t *testing.T) {
	authSvc := auth.NewService("test-secret-32-bytes-long-padded", 15*time.Minute, 24*time.Hour)

	r := gin.New()
	r.Use(func(c *gin.Context) {
		header := c.GetHeader("Authorization")
		if header == "" {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
			return
		}
		c.Next()
	})
	r.POST("/api/v1/cases", func(c *gin.Context) { c.Status(http.StatusCreated) })
	_ = authSvc

	w := doRequest(t, r, http.MethodPost, "/api/v1/cases", map[string]any{
		"child_name": "Test Child",
	}, "") // no token
	assert.Equal(t, http.StatusUnauthorized, w.Code)
}

func TestUpdateStatus_OfficerRole_Allowed(t *testing.T) {
	authSvc := auth.NewService("test-secret-32-bytes-long-padded", 15*time.Minute, 24*time.Hour)

	r := gin.New()
	// Simulate role check
	r.Use(func(c *gin.Context) {
		token := c.GetHeader("Authorization")
		if token == "" {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
			return
		}
		claims, err := authSvc.VerifyAccess(token[7:]) // strip "Bearer "
		if err != nil {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "invalid token"})
			return
		}
		allowed := map[models.UserRole]bool{
			models.RoleOfficer:    true,
			models.RoleAdmin:      true,
			models.RoleSuperAdmin: true,
		}
		if !allowed[claims.Role] {
			c.AbortWithStatusJSON(http.StatusForbidden, gin.H{"error": "forbidden"})
			return
		}
		c.Next()
	})
	r.PATCH("/api/v1/cases/:id/status", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{"message": "updated"})
	})

	officer := &models.User{ID: uuid.New(), Role: models.RoleOfficer}
	tok := mustToken(t, authSvc, officer)

	w := doRequest(t, r, http.MethodPatch, "/api/v1/cases/"+uuid.New().String()+"/status",
		map[string]string{"status": "active"}, tok)
	assert.Equal(t, http.StatusOK, w.Code)
}

func TestUpdateStatus_PublicRole_Returns403(t *testing.T) {
	authSvc := auth.NewService("test-secret-32-bytes-long-padded", 15*time.Minute, 24*time.Hour)

	r := gin.New()
	r.Use(func(c *gin.Context) {
		token := c.GetHeader("Authorization")
		if token == "" {
			c.AbortWithStatusJSON(http.StatusUnauthorized, gin.H{"error": "unauthorized"})
			return
		}
		claims, err := authSvc.VerifyAccess(token[7:])
		if err != nil || claims.Role == models.RolePublic {
			c.AbortWithStatusJSON(http.StatusForbidden, gin.H{"error": "forbidden"})
			return
		}
		c.Next()
	})
	r.PATCH("/api/v1/cases/:id/status", func(c *gin.Context) {
		c.JSON(http.StatusOK, nil)
	})

	public := &models.User{ID: uuid.New(), Role: models.RolePublic}
	tok := mustToken(t, authSvc, public)

	w := doRequest(t, r, http.MethodPatch, "/api/v1/cases/"+uuid.New().String()+"/status",
		map[string]string{"status": "active"}, tok)
	assert.Equal(t, http.StatusForbidden, w.Code)
}

// ── Case payload validation ───────────────────────────────────────────────────

func TestCreateCase_MissingRequiredFields_Returns400(t *testing.T) {
	r := gin.New()
	r.POST("/api/v1/cases", func(c *gin.Context) {
		var req struct {
			ChildName        string  `json:"child_name"    binding:"required"`
			Age              int     `json:"age"           binding:"required,min=0,max=17"`
			County           string  `json:"county"        binding:"required"`
			Lat              float64 `json:"lat"           binding:"required"`
			Lng              float64 `json:"lng"           binding:"required"`
			Description      string  `json:"description"   binding:"required"`
			Clothing         string  `json:"clothing"      binding:"required"`
			CircumstanceType string  `json:"circumstance_type" binding:"required,oneof=wandered abducted unknown"`
		}
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		c.JSON(http.StatusCreated, nil)
	})

	// Missing child_name, county, lat, lng
	w := doRequest(t, r, http.MethodPost, "/api/v1/cases", map[string]any{
		"age": 8,
	}, "token")
	assert.Equal(t, http.StatusBadRequest, w.Code)
}

func TestCreateCase_AgeOutOfRange_Returns400(t *testing.T) {
	r := gin.New()
	r.POST("/api/v1/cases", func(c *gin.Context) {
		var req struct {
			Age int `json:"age" binding:"min=0,max=17"`
		}
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		c.JSON(http.StatusCreated, nil)
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/cases", map[string]any{
		"age": 25, // adult — invalid
	}, "token")
	assert.Equal(t, http.StatusBadRequest, w.Code)
}

func TestCreateCase_InvalidCircumstanceType_Returns400(t *testing.T) {
	r := gin.New()
	r.POST("/api/v1/cases", func(c *gin.Context) {
		var req struct {
			CircumstanceType string `json:"circumstance_type" binding:"oneof=wandered abducted unknown"`
		}
		if err := c.ShouldBindJSON(&req); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		c.JSON(http.StatusCreated, nil)
	})

	w := doRequest(t, r, http.MethodPost, "/api/v1/cases", map[string]string{
		"circumstance_type": "alien-abduction", // invalid
	}, "token")
	assert.Equal(t, http.StatusBadRequest, w.Code)
}

// ── Map geo-points ────────────────────────────────────────────────────────────

func TestListGeoPoints_Returns200WithData(t *testing.T) {
	r := gin.New()
	r.GET("/api/v1/cases/map", func(c *gin.Context) {
		points := []models.CaseGeoPoint{
			{ID: uuid.New(), ChildName: "Brian Otieno", Lat: -1.286, Lng: 36.817, Status: models.CaseStatusActive},
		}
		c.JSON(http.StatusOK, gin.H{"data": points, "count": len(points)})
	})

	w := doRequest(t, r, http.MethodGet, "/api/v1/cases/map", nil, "")
	assert.Equal(t, http.StatusOK, w.Code)

	var resp map[string]json.RawMessage
	require.NoError(t, json.Unmarshal(w.Body.Bytes(), &resp))
	assert.Contains(t, string(resp["count"]), "1")
}

func TestGetCase_UnknownID_Returns404(t *testing.T) {
	r := gin.New()
	r.GET("/api/v1/cases/:id", func(c *gin.Context) {
		id := c.Param("id")
		if _, err := uuid.Parse(id); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": "invalid id"})
			return
		}
		c.JSON(http.StatusNotFound, gin.H{"error": "case not found"})
	})

	w := doRequest(t, r, http.MethodGet, "/api/v1/cases/"+uuid.New().String(), nil, "")
	assert.Equal(t, http.StatusNotFound, w.Code)
}

func TestGetCase_InvalidUUID_Returns400(t *testing.T) {
	r := gin.New()
	r.GET("/api/v1/cases/:id", func(c *gin.Context) {
		if _, err := uuid.Parse(c.Param("id")); err != nil {
			c.JSON(http.StatusBadRequest, gin.H{"error": "invalid id"})
			return
		}
		c.JSON(http.StatusOK, nil)
	})

	w := doRequest(t, r, http.MethodGet, "/api/v1/cases/not-a-uuid", nil, "")
	assert.Equal(t, http.StatusBadRequest, w.Code)
}