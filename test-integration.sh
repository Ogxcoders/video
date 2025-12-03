#!/bin/bash
###############################################################################
# End-to-End Integration Test for Redis Queue Setup
# Tests: Installation → Docker Build → Compress API Endpoint
###############################################################################

echo "========================================"
echo "  Redis Queue Integration Test"
echo "========================================"
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Dependency checks
echo "========================================="
echo "  Dependency Check"
echo "========================================="
echo ""

DEPENDENCIES_MET=true

echo -n "Checking PHP ... "
if command -v php &> /dev/null; then
    echo -e "${GREEN}✓ $(php -v | head -n1)${NC}"
else
    echo -e "${RED}✗ NOT FOUND${NC}"
    DEPENDENCIES_MET=false
fi

echo -n "Checking redis-cli ... "
if command -v redis-cli &> /dev/null; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${YELLOW}⚠ NOT FOUND (some tests will be skipped)${NC}"
fi

echo -n "Checking curl ... "
if command -v curl &> /dev/null; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${RED}✗ NOT FOUND${NC}"
    DEPENDENCIES_MET=false
fi

echo -n "Checking jq ... "
if command -v jq &> /dev/null; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${YELLOW}⚠ NOT FOUND (JSON parsing will be limited)${NC}"
fi

echo -n "Checking Docker ... "
if command -v docker &> /dev/null; then
    echo -e "${GREEN}✓ Found${NC}"
else
    echo -e "${YELLOW}⚠ NOT FOUND (Docker tests will be skipped)${NC}"
fi

echo ""

if [ "$DEPENDENCIES_MET" = false ]; then
    echo -e "${RED}✗ Critical dependencies missing. Please install PHP and curl.${NC}"
    exit 1
fi

set -e

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0

# Test function
test_step() {
    local test_name="$1"
    local test_command="$2"
    
    echo -n "Testing: $test_name ... "
    
    if eval "$test_command" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ PASS${NC}"
        ((TESTS_PASSED++))
        return 0
    else
        echo -e "${RED}✗ FAIL${NC}"
        ((TESTS_FAILED++))
        return 1
    fi
}

echo "========================================="
echo "  Phase 1: Redis Server Tests"
echo "========================================="
echo ""

# Test 1: Redis server is installed
test_step "Redis server installed" "which redis-server"

# Test 2: Redis CLI is installed
test_step "Redis CLI installed" "which redis-cli"

# Test 3: Redis server is running
test_step "Redis server is running" "redis-cli ping | grep -q PONG"

# Test 4: PHP Redis extension loaded
test_step "PHP Redis extension loaded" "php -m | grep -q redis"

echo ""
echo "========================================="
echo "  Phase 2: Queue Class Tests"
echo "========================================="
echo ""

# Test 5: RedisQueue class exists
test_step "RedisQueue class exists" "test -f vps-api/RedisQueue.php"

# Test 6: Run RedisQueue test script
echo -n "Testing: RedisQueue functionality ... "
if php vps-api/test-redis-queue.php > /tmp/redis-queue-test.log 2>&1; then
    if grep -q "All Tests Completed Successfully" /tmp/redis-queue-test.log; then
        echo -e "${GREEN}✓ PASS${NC}"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗ FAIL${NC} (script ran but tests failed)"
        ((TESTS_FAILED++))
        echo "Check /tmp/redis-queue-test.log for details"
    fi
else
    echo -e "${RED}✗ FAIL${NC} (script error)"
    ((TESTS_FAILED++))
    echo "Check /tmp/redis-queue-test.log for details"
fi

echo ""
echo "========================================="
echo "  Phase 3: compress.php API Tests"
echo "========================================="
echo ""

# Test 7: compress.php exists
test_step "compress.php exists" "test -f vps-api/compress.php"

# Test 8: Test compress API endpoint (requires PHP server)
echo -n "Testing: Compress API endpoint ... "
if php -S localhost:8765 -t vps-api > /dev/null 2>&1 &
then
    PHP_SERVER_PID=$!
    sleep 2
    
    # Make test request
    RESPONSE=$(curl -s -X POST http://localhost:8765/compress.php \
        -H "Content-Type: application/json" \
        -H "X-API-Key: CHANGE_ME_TO_A_SECURE_RANDOM_KEY" \
        -d '{
            "postId": 99999,
            "wpMediaPath": "/wp-content/uploads/2024/11/test.mp4",
            "wpThumbnailPath": "/wp-content/uploads/2024/11/test.jpg",
            "year": 2024,
            "month": 11
        }')
    
    # Kill PHP server
    kill $PHP_SERVER_PID > /dev/null 2>&1 || true
    
    # Check response
    if echo "$RESPONSE" | grep -q '"jobId"'; then
        echo -e "${GREEN}✓ PASS${NC}"
        ((TESTS_PASSED++))
        echo "  Response: $(echo $RESPONSE | jq -r '.jobId' 2>/dev/null || echo 'Job created')"
    else
        echo -e "${RED}✗ FAIL${NC}"
        ((TESTS_FAILED++))
        echo "  Response: $RESPONSE"
    fi
else
    echo -e "${YELLOW}⚠ SKIP${NC} (could not start PHP server)"
fi

echo ""
echo "========================================="
echo "  Phase 4: Persistence Tests"
echo "========================================="
echo ""

# Test 9: AOF file exists
test_step "AOF persistence enabled" "redis-cli CONFIG GET appendonly | grep -q yes"

# Test 10: RDB enabled
test_step "RDB persistence configured" "redis-cli CONFIG GET save | grep -q 900"

echo ""
echo "========================================="
echo "  Phase 5: Docker Tests (Optional)"
echo "========================================="
echo ""

if command -v docker &> /dev/null; then
    # Test 11: Dockerfile exists
    test_step "Dockerfile exists" "test -f vps-api/Dockerfile"
    
    # Test 12: redis.conf exists
    test_step "redis.conf exists" "test -f vps-api/redis.conf"
    
    echo -n "Testing: Docker build ... "
    if docker build -t vps-api-test -f vps-api/Dockerfile vps-api > /tmp/docker-build.log 2>&1; then
        echo -e "${GREEN}✓ PASS${NC}"
        ((TESTS_PASSED++))
        
        # Cleanup
        docker rmi vps-api-test > /dev/null 2>&1 || true
    else
        echo -e "${RED}✗ FAIL${NC}"
        ((TESTS_FAILED++))
        echo "  Check /tmp/docker-build.log for details"
    fi
else
    echo -e "${YELLOW}⚠ SKIP${NC} (Docker not installed)"
fi

echo ""
echo "========================================="
echo "  Test Summary"
echo "========================================="
echo ""
echo "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
echo "Tests Failed: ${RED}$TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed! Redis Queue Setup is production-ready.${NC}"
    exit 0
else
    echo -e "${RED}✗ Some tests failed. Please review the output above.${NC}"
    exit 1
fi
