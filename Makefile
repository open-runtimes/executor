.PHONY: help start-docker test-docker helm-clean helm-install helm-install-local helm-upgrade helm-uninstall helm-test kind-create kind-delete kind-load kind-full docker-build docker-push deploy stop clean

# Configuration
IMAGE_NAME ?= openruntimes/executor
IMAGE_TAG ?= latest
HELM_RELEASE ?= executor
HELM_NAMESPACE ?= default
KIND_CLUSTER ?= executor-cluster

# Default target
help:
	@echo "Open Runtimes Executor - Make Commands"
	@echo ""
	@echo "Docker Runner:"
	@echo "  make start-docker    - Start executor with Docker runner"
	@echo "  make test-docker     - Run tests against Docker executor"
	@echo "  make logs-docker     - View Docker executor logs"
	@echo ""
	@echo "Helm Deployment:"
	@echo "  make helm-clean      - Clean up conflicting resources"
	@echo "  make helm-install    - Install executor with Helm"
	@echo "  make helm-upgrade    - Upgrade executor Helm release"
	@echo "  make helm-uninstall  - Uninstall executor Helm release"
	@echo "  make helm-test       - Port-forward and test Helm deployment"
	@echo ""
	@echo "Kind (Local Kubernetes):"
	@echo "  make kind-create     - Create Kind cluster"
	@echo "  make kind-delete     - Delete Kind cluster"
	@echo "  make kind-load       - Build and load image to Kind"
	@echo "  make kind-full       - Full setup: create cluster, load image, install"
	@echo ""
	@echo "Docker Image:"
	@echo "  make docker-build    - Build executor Docker image"
	@echo "  make docker-push     - Push image to registry"
	@echo ""
	@echo "Combined:"
	@echo "  make deploy          - Build, load to kind, and install with helm"
	@echo ""
	@echo "General:"
	@echo "  make stop            - Stop Docker executor"
	@echo "  make clean           - Clean up everything"

# Docker Runner targets
start-docker:
	@echo "Starting Executor with Docker runner..."
	docker-compose up -d
	@echo "Executor is running on http://localhost:80"

logs-docker:
	docker-compose logs -f

# Helm targets
helm-clean:
	@echo "Cleaning up existing resources that conflict with Helm..."
	-kubectl delete serviceaccount executor-sa -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete role executor-role -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete rolebinding executor-rolebinding -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete deployment executor-k8s -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete service executor-k8s -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete configmap executor-k8s-config -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete secret executor-k8s-secret -n $(HELM_NAMESPACE) 2>/dev/null || true
	@echo "Cleanup complete!"

helm-install: helm-clean
	@echo "Installing Executor with Helm..."
	helm install $(HELM_RELEASE) ./deploy \
		--namespace $(HELM_NAMESPACE) \
		--create-namespace \
		--wait
	@echo ""
	@echo "Executor installed successfully!"
	@echo "To access the executor, run:"
	@echo "  make helm-test"

helm-install-local: helm-clean
	@echo "Installing Executor with Helm (local Kind values)..."
	helm install $(HELM_RELEASE) ./deploy \
		-f ./deploy/values-local.yaml \
		--namespace $(HELM_NAMESPACE) \
		--create-namespace \
		--wait
	@echo ""
	@echo "Executor installed successfully!"
	@echo "To access the executor, run:"
	@echo "  make helm-test"

helm-upgrade:
	@echo "Upgrading Executor..."
	helm upgrade $(HELM_RELEASE) ./deploy \
		--namespace $(HELM_NAMESPACE) \
		--wait
	@echo "Executor upgraded successfully!"

helm-uninstall:
	@echo "Uninstalling Executor..."
	helm uninstall $(HELM_RELEASE) --namespace $(HELM_NAMESPACE)
	@echo "Executor uninstalled!"

helm-test:
	@echo "Port-forwarding to executor service..."
	@echo "Executor will be available at http://localhost:8080"
	@echo "Press Ctrl+C to stop"
	@kubectl port-forward -n $(HELM_NAMESPACE) svc/$(HELM_RELEASE)-openruntimes-executor 8080:80

# Kind targets
kind-create:
	@echo "Creating Kind cluster..."
	@if kind get clusters | grep -q $(KIND_CLUSTER); then \
		echo "Cluster $(KIND_CLUSTER) already exists"; \
	else \
		kind create cluster --name $(KIND_CLUSTER); \
		echo "Cluster created successfully!"; \
	fi

kind-delete:
	@echo "Deleting Kind cluster..."
	kind delete cluster --name $(KIND_CLUSTER)
	@echo "Cluster deleted!"

kind-load:
	@echo "Building and loading image to Kind..."
	docker build -t $(IMAGE_NAME):$(IMAGE_TAG) .
	kind load docker-image $(IMAGE_NAME):$(IMAGE_TAG) --name $(KIND_CLUSTER)
	@echo "Image loaded successfully!"

kind-full: kind-create kind-load helm-install-local
	@echo ""
	@echo "✅ Full setup complete!"
	@echo "Run 'make helm-test' to access the executor"

# Docker image targets
docker-build:
	@echo "Building Docker image..."
	docker build -t $(IMAGE_NAME):$(IMAGE_TAG) .
	@echo "Image built successfully!"

docker-push: docker-build
	@echo "Pushing Docker image..."
	docker push $(IMAGE_NAME):$(IMAGE_TAG)
	@echo "Image pushed successfully!"

# Combined deployment
deploy: kind-load helm-upgrade
	@echo ""
	@echo "✅ Deployment complete!"
	@echo "Run 'make helm-test' to access the executor"

# Testing targets
test-docker:
	@echo "Running tests against Docker executor..."
	composer test

# Cleanup targets
stop:
	@echo "Stopping Docker executor..."
	-docker-compose down

clean:
	@echo "Cleaning up..."
	@echo "Stopping Docker executor..."
	-docker-compose down
	@echo "Uninstalling Helm release..."
	-helm uninstall $(HELM_RELEASE) --namespace $(HELM_NAMESPACE) 2>/dev/null || true
	@echo "Cleaning up conflicting Kubernetes resources..."
	-kubectl delete serviceaccount executor-sa -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete role executor-role -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete rolebinding executor-rolebinding -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete deployment executor-k8s -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete service executor-k8s -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete configmap executor-k8s-config -n $(HELM_NAMESPACE) 2>/dev/null || true
	-kubectl delete secret executor-k8s-secret -n $(HELM_NAMESPACE) 2>/dev/null || true
	@echo "Deleting Kind cluster..."
	-kind delete cluster --name $(KIND_CLUSTER) 2>/dev/null || true
	@echo "Cleaning Docker..."
	-docker system prune -f
	@echo "Cleanup complete!"

# Development targets
install:
	composer install

lint:
	composer lint

format:
	composer format

check:
	composer check

# Build target
build:
	docker-compose build
