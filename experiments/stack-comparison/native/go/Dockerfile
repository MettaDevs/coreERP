FROM golang:1.26.4-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 go build -trimpath -o /native-go .

FROM alpine:3.22
RUN adduser -D -u 10001 native
USER native
COPY --from=build /native-go /native-go
ENV LISTEN_ADDR=0.0.0.0:8000
EXPOSE 8000
ENTRYPOINT ["/native-go"]
