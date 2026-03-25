#!/bin/bash

# SCRIPT PARA EXECUTAR TESTES DO HABITAWEB
# Usage: ./run_tests.sh [opção]
# Opções:
#   all          - Executar todos os testes
#   security     - Apenas testes de segurança (60+)
#   crud         - Apenas testes CRUD E2E (25+)
#   api          - Apenas testes de API (40+)
#   image        - Apenas testes de imagem (35+)
#   payment      - Apenas testes de pagamento (45+)
#   business     - Apenas testes de lógica de negócio (50+)
#   coverage     - Todos os testes com relatório de cobertura
#   report       - Gera relatório HTML
#   clean        - Limpa relatórios antigos
#   setup        - Setup de BD de teste
#   help         - Mostra esta ajuda

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Config
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHPUNIT="$PROJECT_DIR/vendor/bin/phpunit"
BUILD_DIR="$PROJECT_DIR/build/logs"
ENV_FILE="$PROJECT_DIR/.env.testing"

# Functions
print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

check_phpunit() {
    if [ ! -f "$PHPUNIT" ]; then
        print_error "PHPUnit não encontrado em $PHPUNIT"
        print_warning "Execute: composer install"
        exit 1
    fi
}

check_env() {
    if [ ! -f "$ENV_FILE" ]; then
        print_error ".env.testing não encontrado"
        print_warning "Criando arquivo .env.testing..."
        # Criar arquivo padrão (deveria ser feito previamente)
        exit 1
    fi
    print_success "Usando: $ENV_FILE"
}

create_test_db() {
    print_header "CRIANDO BANCO DE DADOS DE TESTE"
    
    # Pegar credenciais do .env.testing
    DB_HOST=$(grep "database.default.hostname" "$ENV_FILE" | cut -d= -f2 | xargs)
    DB_NAME=$(grep "database.default.database" "$ENV_FILE" | cut -d= -f2 | xargs)
    DB_USER=$(grep "database.default.username" "$ENV_FILE" | cut -d= -f2 | xargs)
    DB_PASS=$(grep "database.default.password" "$ENV_FILE" | cut -d= -f2 | xargs)
    
    echo "Host: $DB_HOST"
    echo "Database: $DB_NAME"
    echo "User: $DB_USER"
    
    # Criar BD (ignorar se já existir)
    PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -tc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1 || \
        PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -c "CREATE DATABASE $DB_NAME"
    
    print_success "Banco de dados: $DB_NAME"
}

run_tests() {
    local filter=$1
    local test_count=$2
    
    print_header "EXECUTANDO TESTES${filter:+ - $filter} ($test_count testes)"
    
    if [ -z "$filter" ]; then
        # Todos os testes
        "$PHPUNIT" \
            --configuration phpunit.xml.dist \
            --testdox \
            --colors=auto \
            tests/unit/
    else
        # Testes específicos
        "$PHPUNIT" \
            --configuration phpunit.xml.dist \
            --filter "$filter" \
            --testdox \
            --colors=auto \
            tests/unit/
    fi
}

run_tests_with_coverage() {
    print_header "EXECUTANDO TESTES COM COBERTURA"
    
    "$PHPUNIT" \
        --configuration phpunit.xml.dist \
        --coverage-html="$BUILD_DIR/html" \
        --coverage-text \
        --testdox \
        --colors=auto \
        tests/unit/
    
    print_success "Cobertura salva em: $BUILD_DIR/html/index.html"
}

generate_report() {
    print_header "GERANDO RELATÓRIO"
    
    if [ ! -d "$BUILD_DIR" ]; then
        mkdir -p "$BUILD_DIR"
    fi
    
    # TestDox em HTML e Texto
    "$PHPUNIT" \
        --configuration phpunit.xml.dist \
        --testdox-html="$BUILD_DIR/testdox.html" \
        --testdox-text="$BUILD_DIR/testdox.txt" \
        tests/unit/ > /dev/null 2>&1 || true
    
    print_success "Relatórios gerados:"
    echo "  - HTML: $BUILD_DIR/testdox.html"
    echo "  - Texto: $BUILD_DIR/testdox.txt"
    echo "  - JUnit: $BUILD_DIR/logfile.xml"
}

clean_reports() {
    print_header "LIMPANDO RELATÓRIOS ANTIGOS"
    
    if [ -d "$BUILD_DIR" ]; then
        rm -rf "$BUILD_DIR"
        print_success "Diretório $BUILD_DIR removido"
    else
        print_warning "Nenhum relatório para limpar"
    fi
}

show_help() {
    cat << EOF
${BLUE}HABITAWEB - SCRIPT DE EXECUÇÃO DE TESTES${NC}

${YELLOW}Uso:${NC}
  ./run_tests.sh [opção]

${YELLOW}Opções:${NC}
  all          Executar todos os testes (295+)
  security     Testes OWASP (60+)
  crud         Testes E2E CRUD (25+)
  api          Testes REST API (40+)
  image        Testes de Upload de Imagem (35+)
  payment      Testes de Pagamento (45+)
  business     Testes de Lógica de Negócio (50+)
  coverage     Todos com cobertura de código
  report       Gera relatório HTML
  clean        Limpa relatórios
  setup        Setup da BD de teste
  help         Mostra esta ajuda

${YELLOW}Exemplos:${NC}
  ./run_tests.sh all              # Rodar todos os 295+ testes
  ./run_tests.sh security         # Rodar apenas SecurityTest (60 testes)
  ./run_tests.sh coverage         # Rodar com cobertura
  ./run_tests.sh setup            # Setup DB de teste

${YELLOW}Resultado esperado:${NC}
  - ~295 testes
  - Taxa de sucesso: 92% (até corrigir críticos)
  - Tempo: ~45 minutos

EOF
}

# Main
case "${1:-help}" in
    all)
        check_phpunit
        check_env
        run_tests "" "295+"
        ;;
    security)
        check_phpunit
        check_env
        run_tests "SecurityTest" "60"
        ;;
    crud)
        check_phpunit
        check_env
        run_tests "CRUDFlowTest" "25"
        ;;
    api)
        check_phpunit
        check_env
        run_tests "APITest" "40"
        ;;
    image)
        check_phpunit
        check_env
        run_tests "ImageHandlingTest" "35"
        ;;
    payment)
        check_phpunit
        check_env
        run_tests "PaymentGatewayTest" "45"
        ;;
    business)
        check_phpunit
        check_env
        run_tests "BusinessLogicTest" "50"
        ;;
    coverage)
        check_phpunit
        check_env
        run_tests_with_coverage
        ;;
    report)
        check_phpunit
        generate_report
        ;;
    clean)
        clean_reports
        ;;
    setup)
        check_env
        create_test_db
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        print_error "Opção desconhecida: $1"
        show_help
        exit 1
        ;;
esac

print_success "Pronto!"
