#!/bin/bash

# SCRIPT PARA EXECUTAR TESTES DO HABITAWEB
# Usage: ./run_tests.sh [opção]
# Opções:
#   all          - unit + feature + e2e (exclui @group asaas-sandbox)
#   unit         - Só tests/unit (smoke tests que não precisam de HTTP simulado)
#   feature      - Só tests/Feature (API/IDOR, AdminAuth, cupom, upload, webhook...)
#   e2e          - Só tests/E2E (cenários completos de assinatura)
#   sandbox      - Testes @group asaas-sandbox (bate no Asaas sandbox real)
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

    # IMPORTANTE: .env.testing NÃO é lido pelo PHPUnit. O bootstrap do CI4
    # (vendor/codeigniter4/framework/system/Test/bootstrap.php) força
    # ENVIRONMENT=testing incondicionalmente, o que faz Config\Database usar o
    # grupo 'tests' — cujos valores vêm das tags <env name="database.tests.*">
    # em phpunit.xml.dist, não de .env.testing. Lemos daqui para garantir que o
    # banco criado é exatamente o que os testes vão usar de verdade.
    # -m1: o arquivo tem um bloco de exemplo COMENTADO mais abaixo com as mesmas
    # chaves (database.tests.* apontando MySQLi/tests_user) — sem -m1 o grep pega
    # as duas ocorrências. A config ativa vem sempre primeiro no arquivo.
    PHPUNIT_CONFIG="$PROJECT_DIR/phpunit.xml.dist"
    DB_HOST=$(grep -m1 'name="database.tests.hostname"' "$PHPUNIT_CONFIG" | sed -E 's/.*value="([^"]*)".*/\1/')
    DB_NAME=$(grep -m1 'name="database.tests.database"' "$PHPUNIT_CONFIG" | sed -E 's/.*value="([^"]*)".*/\1/')
    DB_USER=$(grep -m1 'name="database.tests.username"' "$PHPUNIT_CONFIG" | sed -E 's/.*value="([^"]*)".*/\1/')
    DB_PASS=$(grep -m1 'name="database.tests.password"' "$PHPUNIT_CONFIG" | sed -E 's/.*value="([^"]*)".*/\1/')

    echo "Host: $DB_HOST"
    echo "Database: $DB_NAME"
    echo "User: $DB_USER"

    # Criar BD (ignorar se já existir)
    PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -tc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1 || \
        PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -c "CREATE DATABASE $DB_NAME"

    print_success "Banco de dados: $DB_NAME"
}

run_tests() {
    local suites=${1:-unit,feature,e2e}

    print_header "EXECUTANDO TESTES (--testsuite ${suites})"

    # exclui @group asaas-sandbox (rede/credenciais externas — roda só via
    # './run_tests.sh sandbox' explicitamente).
    "$PHPUNIT" \
        --configuration phpunit.xml.dist \
        --testsuite "$suites" \
        --exclude-group asaas-sandbox \
        --testdox \
        --colors=auto
}

run_tests_with_coverage() {
    print_header "EXECUTANDO TESTES COM COBERTURA"

    "$PHPUNIT" \
        --configuration phpunit.xml.dist \
        --testsuite unit,feature,e2e \
        --exclude-group asaas-sandbox \
        --coverage-html="$BUILD_DIR/html" \
        --coverage-text \
        --testdox \
        --colors=auto

    print_success "Cobertura salva em: $BUILD_DIR/html/index.html"
}

run_sandbox_tests() {
    print_header "EXECUTANDO TESTES @group asaas-sandbox (Asaas sandbox real)"
    print_warning "Requer credenciais de sandbox em .env.testing (ASAAS_ENV=sandbox, ASAAS_API_KEY...)."

    "$PHPUNIT" \
        --configuration phpunit.xml.dist \
        --testsuite feature,e2e \
        --group asaas-sandbox \
        --testdox \
        --colors=auto
}

generate_report() {
    print_header "GERANDO RELATÓRIO"

    if [ ! -d "$BUILD_DIR" ]; then
        mkdir -p "$BUILD_DIR"
    fi

    # TestDox em HTML e Texto
    "$PHPUNIT" \
        --configuration phpunit.xml.dist \
        --testsuite unit,feature,e2e \
        --exclude-group asaas-sandbox \
        --testdox-html="$BUILD_DIR/testdox.html" \
        --testdox-text="$BUILD_DIR/testdox.txt" \
        > /dev/null 2>&1 || true

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
  all          unit + feature + e2e (exclui @group asaas-sandbox)
  unit         Só tests/unit (smoke tests)
  feature      Só tests/Feature (API/IDOR, AdminAuth, cupom, upload, webhook...)
  e2e          Só tests/E2E (cenários completos de assinatura)
  sandbox      Testes @group asaas-sandbox (bate no Asaas sandbox real; precisa
               de credenciais em .env.testing — não roda no CI padrão)
  coverage     unit + feature + e2e com cobertura de código
  report       Gera relatório HTML
  clean        Limpa relatórios
  setup        Cria o banco de teste (lendo phpunit.xml.dist, fonte real de verdade)
  help         Mostra esta ajuda

${YELLOW}Exemplos:${NC}
  ./run_tests.sh setup            # Cria o BD de teste antes da primeira execução
  ./run_tests.sh all              # Roda a suíte padrão (sem sandbox)
  ./run_tests.sh feature          # Só as suítes novas de feature
  ./run_tests.sh sandbox          # Só os testes que dependem do Asaas sandbox
  ./run_tests.sh coverage         # Com cobertura

EOF
}

# Main
case "${1:-help}" in
    all)
        check_phpunit
        check_env
        run_tests "unit,feature,e2e"
        ;;
    unit)
        check_phpunit
        check_env
        run_tests "unit"
        ;;
    feature)
        check_phpunit
        check_env
        run_tests "feature"
        ;;
    e2e)
        check_phpunit
        check_env
        run_tests "e2e"
        ;;
    sandbox)
        check_phpunit
        check_env
        run_sandbox_tests
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
