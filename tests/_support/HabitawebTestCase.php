<?php

namespace Tests\Support;

use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Base REAL de testes do Habitaweb.
 *
 * Diferente das bases legadas (Tests\TestCase e App\Test\TestCase), esta sobe o
 * framework de verdade: get/post/put/delete roteiam pelo CodeIgniter, passam pelos
 * filtros e controllers reais, e assertResponseStatus() é o do framework — não um
 * assertTrue(true). Use esta base para toda suíte nova (feature/e2e).
 *
 * Isolamento: DatabaseTestTrait envolve cada teste numa transação e faz rollback no
 * tearDown, então os inserts de um teste não vazam para o próximo. As migrations
 * rodam uma única vez por execução ($migrateOnce), cobrindo todos os namespaces
 * (Shield, Settings e App) via $namespace = null.
 */
abstract class HabitawebTestCase extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;
    use AuthenticationTesting;

    /**
     * Grupo de conexão de teste (Postgres habitaweb_test). Alinhado ao phpunit.xml.dist,
     * que define database.tests.* — não use 'default' aqui.
     */
    protected $dbGroup = 'tests';

    /** Roda migrations antes dos testes. */
    protected $migrate = true;

    /** Migra uma única vez para a execução inteira (não re-migra por teste). */
    protected $migrateOnce = true;

    /** Não regride/re-migra a cada teste (lento; a transação manual abaixo isola). */
    protected $refresh = false;

    /** null = migra TODOS os namespaces (App + CodeIgniter\Shield + CodeIgniter\Settings). */
    protected $namespace = null;

    /**
     * IMPORTANTE: ao contrário do que a documentação/senso comum sugere, o
     * DatabaseTestTrait do CI4 NÃO envolve cada teste numa transação com rollback —
     * ele só cuida de migrate/seed (ver CIUnitTestCase::setUp -> setUpDatabase()).
     * Sem isso, cada teste que insere dados (ex.: TenantFactory) deixa lixo
     * permanente no banco de teste. Por isso fazemos a transação nós mesmos aqui.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // resetServices() limpa a instância compartilhada de auth() do Shield (que
        // cacheia o usuário logado do teste anterior); mockSession() precisa vir
        // depois, senão o reset também descartaria a sessão mockada.
        $this->resetServices();
        $this->mockSession();

        $this->db->transStart();
    }

    protected function tearDown(): void
    {
        $this->db->transRollback();

        parent::tearDown();
    }

    /**
     * Aliases legiveis para as asserções de banco do DatabaseTestTrait
     * (seeInDatabase / dontSeeInDatabase), mantendo compatibilidade com a nomenclatura
     * usada nas suítes antigas — porém agora com verificação REAL (sem swallow de exceção).
     */
    protected function assertDatabaseHas(string $table, array $where): void
    {
        $this->seeInDatabase($table, $where);
    }

    protected function assertDatabaseMissing(string $table, array $where): void
    {
        $this->dontSeeInDatabase($table, $where);
    }
}
