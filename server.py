import subprocess
import os
import sys
print("Starting PHP built-in server on port 3000...")
try:
    subprocess.run(["php", "-S", "0.0.0.0:3000", "-t", "/app"], check=True)
except KeyboardInterrupt:
    print("Server stopped.")
    sys.exit(0)
except Exception as e:
    print(f"Failed to start PHP server: {e}")
    sys.exit(1)
