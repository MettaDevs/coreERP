FROM rust@sha256:a41f7740f8b45d45795624eec13a8b42263cc700f19f7e4e86e04d3dda08a479 AS build
WORKDIR /build
COPY Cargo.toml Cargo.lock ./
COPY src ./src
RUN cargo build --release --locked -j 2

FROM scratch
COPY --from=build /build/target/release/coreerp-native-comparison /coreerp-native-comparison
USER 65532:65532
ENV BIND_HOST=0.0.0.0 PORT=18643
EXPOSE 18643
ENTRYPOINT ["/coreerp-native-comparison"]
