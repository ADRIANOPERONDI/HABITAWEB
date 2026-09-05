<?php

namespace Tests\Feature;

use Tests\Support\HabitawebTestCase;

/**
 * Cobre um bug de infraestrutura de teste, não de código de produção:
 * `BaseConnection::$transStatus` é uma flag da CONEXÃO (persistente no PHP
 * enquanto o processo do PHPUnit roda), não da transação. Só `transComplete()`
 * fora de modo estrito, ou `resetTransStatus()` explícito, a limpa —
 * `transRollback()` sozinho não a toca.
 *
 * Qualquer teste que provoque um erro de propósito no banco (para provar uma
 * validação, uma constraint UNIQUE etc.) deixava essa flag em `false` GRUDADA
 * na conexão compartilhada para todo o resto do processo. Todo teste seguinte
 * que fizesse `transStart()`/`transComplete()` — exatamente o padrão de
 * PaymentService, PromotionService e TurboService — veria `transStatus()`
 * falso mesmo com as PRÓPRIAS queries perfeitamente bem-sucedidas.
 *
 * Encontrado depurando TurboServiceTest: os 12 testes passavam isolados, mas 6
 * quebravam quando rodados depois de PromotionsQuotaSchemaTest, cujo próprio
 * teste de idempotência provoca uma violação de UNIQUE de propósito.
 *
 * A ordem dos dois testes abaixo importa — não use --filter isolado nem
 * @depends aqui: o objetivo é que o PRIMEIRO teste envenene a conexão e o
 * SEGUNDO prove que HabitawebTestCase::setUp() já limpou o veneno.
 */
final class TestHarnessTransStatusTest extends HabitawebTestCase
{
    public function testAProvocaUmaFalhaDePropositoNoBanco(): void
    {
        // DBDebug=false no ambiente: isto não lança, só marca transStatus=false
        // na conexão.
        $this->db->query('SELECT 1/0');

        $this->assertFalse($this->db->transStatus());
    }

    public function testBComecaLimpoApesarDaFalhaDoTesteAnterior(): void
    {
        $this->assertTrue(
            $this->db->transStatus(),
            'setUp() precisa ter chamado resetTransStatus() — senão o teste anterior contamina este.'
        );

        $this->db->transStart();
        $this->db->query('SELECT 1');
        $this->db->transComplete();

        $this->assertTrue(
            $this->db->transStatus(),
            'Uma operação 100% bem-sucedida não pode reportar falha por causa de um erro de OUTRO teste.'
        );
    }
}
