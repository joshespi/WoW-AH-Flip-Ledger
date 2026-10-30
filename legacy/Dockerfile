FROM python:3.12-slim

# tzdata so timestamps print in local time
RUN apt-get update \
    && apt-get install -y --no-install-recommends tzdata \
    && rm -rf /var/lib/apt/lists/*

COPY tsm_ledger.py /app/tsm_ledger.py

WORKDIR /data
ENTRYPOINT ["python", "/app/tsm_ledger.py", "--wow", "/wow"]
CMD ["--help"]
