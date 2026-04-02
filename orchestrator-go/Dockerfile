FROM golang:1.22-alpine AS build
WORKDIR /src
COPY . .
RUN go build -o /bin/orchestrator ./cmd/orchestrator

FROM alpine:3.19
RUN apk add --no-cache ca-certificates docker-cli
WORKDIR /app
COPY --from=build /bin/orchestrator /app/orchestrator
EXPOSE 8098
ENTRYPOINT ["/app/orchestrator"]
