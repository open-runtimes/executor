#!/bin/bash

set -e

# Configuration
EXECUTOR_URL="http://localhost:8080"
SECRET_KEY="local-dev-secret-key"
RUNTIME_ID="test-$(date +%s)"

echo "🚀 Testing Kubernetes Executor"
echo "Runtime ID: $RUNTIME_ID"
echo ""

# Health check
echo "📊 Health Check"
curl -s -X GET "${EXECUTOR_URL}/v1/health" \
  -H "Authorization: Bearer ${SECRET_KEY}" | jq .
echo ""

# List runtimes (should be empty)
echo "📋 List Runtimes (should be empty)"
curl -s -X GET "${EXECUTOR_URL}/v1/runtimes" \
  -H "Authorization: Bearer ${SECRET_KEY}" | jq .
echo ""

# Create runtime
echo "🔧 Create Runtime"
curl -s -X POST "${EXECUTOR_URL}/v1/runtimes" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${SECRET_KEY}" \
  -d "{
    \"runtimeId\": \"${RUNTIME_ID}\",
    \"image\": \"openruntimes/php:v5-8.3\",
    \"entrypoint\": \"index.php\",
    \"version\": \"v5\",
    \"cpus\": 0.5,
    \"memory\": 256,
    \"timeout\": 60
  }" | jq .
echo ""

# Wait for runtime
echo "⏳ Waiting for runtime to be ready..."
sleep 3

# Get runtime details
echo "🔍 Get Runtime Details"
curl -s -X GET "${EXECUTOR_URL}/v1/runtimes/${RUNTIME_ID}" \
  -H "Authorization: Bearer ${SECRET_KEY}" | jq .
echo ""

# Execute command
echo "💻 Execute Command"
curl -s -X POST "${EXECUTOR_URL}/v1/runtimes/${RUNTIME_ID}/commands" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${SECRET_KEY}" \
  -d '{
    "command": "php -v",
    "timeout": 10
  }' | jq .
echo ""

# Delete runtime
echo "🗑️  Delete Runtime"
curl -s -X DELETE "${EXECUTOR_URL}/v1/runtimes/${RUNTIME_ID}" \
  -H "Authorization: Bearer ${SECRET_KEY}"
echo ""

# Verify deletion
echo "✅ Verify Deletion"
sleep 2
curl -s -X GET "${EXECUTOR_URL}/v1/runtimes" \
  -H "Authorization: Bearer ${SECRET_KEY}" | jq .
echo ""

echo "✨ Test complete!"
