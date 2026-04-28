FROM python:3.11-slim
RUN apt-get update && apt-get install -y php php-curl php-json php-mbstring && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . .
RUN chmod +x server.py
EXPOSE 3000
CMD ["python", "server.py"]
