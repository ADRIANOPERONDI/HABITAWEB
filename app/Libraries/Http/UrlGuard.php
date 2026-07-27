<?php

namespace App\Libraries\Http;

/**
 * Guarda de SSRF para URLs fornecidas por terceiros.
 *
 * Existem dois pontos do sistema em que uma URL enviada pelo cliente vira uma
 * requisição HTTP saindo do NOSSO servidor:
 *   - ingestão de imagem por URL no import do parceiro (RemoteImageFetcher);
 *   - entrega de webhook para a target_url cadastrada (WebhookService).
 *
 * Sem validação, esses dois viram um proxy para a rede interna: o atacante
 * cadastra http://169.254.169.254/latest/meta-data/ ou http://10.0.0.5/admin e
 * usa nosso servidor para alcançar o que ele não alcança de fora.
 */
class UrlGuard
{
    /**
     * Valida esquema e destino de uma URL.
     *
     * @return array{valid: bool, message?: string}
     */
    public function validate(string $url): array
    {
        $url = trim($url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'message' => 'URL inválida.'];
        }

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'message' => 'Apenas URLs http ou https são aceitas.'];
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return ['valid' => false, 'message' => 'URL sem host.'];
        }

        return $this->validateHost($host);
    }

    /**
     * Rejeita hosts que resolvem para endereços internos.
     *
     * @return array{valid: bool, message?: string}
     */
    public function validateHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = array_merge(
                gethostbynamel($host) ?: [],
                array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
            );

            if ($ips === []) {
                return ['valid' => false, 'message' => 'Não foi possível resolver o host da URL.'];
            }
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return [
                    'valid'   => false,
                    'message' => 'A URL aponta para um endereço de rede interna e foi bloqueada.',
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE cobre 10/8, 172.16/12, 192.168/16,
     * 127/8, 169.254/16, ::1, fc00::/7 e afins. A checagem explícita do endereço
     * de metadados de nuvem fica como cinto e suspensório.
     */
    public function isPublicIp(string $ip): bool
    {
        if ($ip === '169.254.169.254' || $ip === 'fd00:ec2::254') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
