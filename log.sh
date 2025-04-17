# log.sh

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Log functions
log_info() {
  echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
  echo -e "${GREEN}[✔]${NC} $1"
}

log_warning() {
  echo -e "${YELLOW}[⚠️ ]${NC} $1"
}

log_error() {
  echo -e "${RED}[✖]${NC} $1"
}
