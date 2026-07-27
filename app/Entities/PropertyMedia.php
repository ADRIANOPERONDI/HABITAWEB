<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class PropertyMedia extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * O 't'/'f' do PostgreSQL é tratado nativamente pelo BooleanCast do CI 4.7
     * (system/DataCaster/Cast/BooleanCast.php), então basta declarar o cast.
     *
     * Havia aqui um override de castAs() que tentava resolver isso à mão, mas
     * comparava $attribute === 'boolean' — e o CI passa nesse parâmetro o NOME
     * do atributo ('principal'), não o tipo do cast. A condição nunca era
     * verdadeira e o método inteiro era código morto.
     *
     * Importante: para linhas lidas via Model, quem aplica o cast é o
     * $casts do MODEL (PropertyMediaModel), não este.
     */
    protected $casts = [
        'principal' => 'boolean',
    ];
}
