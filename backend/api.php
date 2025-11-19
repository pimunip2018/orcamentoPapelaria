<?php
// backend/api.php

require __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

// ----------------- HEADERS / CORS -----------------
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // em produção, restringir ao domínio do front
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ----------------- HELPERS -----------------
function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ----------------- CONEXÃO MONGODB -----------------
try {
    // No Render, defina a variável de ambiente MONGODB_URI
    // Ex: mongodb+srv://usuario:senha@cluster0.xxx.mongodb.net
    $mongoUri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';

    $client   = new Client($mongoUri);
    $database = $client->selectDatabase('orcamento_papelaria');

    $produtosCol    = $database->selectCollection('produtos');
    $orcamentosCol  = $database->selectCollection('orcamentos');

} catch (Exception $e) {
    jsonResponse(['message' => 'Erro de conexão com o MongoDB: ' . $e->getMessage()], 500);
}

// ----------------- ROTEAMENTO -----------------
$recurso = $_GET['recurso'] ?? ''; // "produtos" ou "orcamentos"
$id      = $_GET['id']      ?? null;
$method  = $_SERVER['REQUEST_METHOD'];
$body    = getJsonBody();

// ----------------- PRODUTOS -----------------
if ($recurso === 'produtos') {

    // LISTAR
    if ($method === 'GET') {
        try {
            $docs = $produtosCol->find([], ['sort' => ['descricao' => 1]])->toArray();
            $produtos = array_map(function($p) {
                $p['_id'] = (string)$p['_id'];
                return $p;
            }, $docs);
            jsonResponse($produtos);
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao buscar produtos: ' . $e->getMessage()], 500);
        }
    }

    // CRIAR
    if ($method === 'POST') {
        $camposObrig = ['quantidade','descricao','precoPacote','loja','valorFinalUnitario'];
        foreach ($camposObrig as $c) {
            if (!isset($body[$c]) || $body[$c] === '') {
                jsonResponse(['message' => "Campo obrigatório ausente: $c"], 400);
            }
        }

        $doc = [
            'quantidade'        => (float)$body['quantidade'],
            'descricao'         => (string)$body['descricao'],
            'precoPacote'       => (float)$body['precoPacote'],
            'precoAnterior'     => isset($body['precoAnterior']) ? (float)$body['precoAnterior'] : 0.0,
            'loja'              => (string)$body['loja'],
            'valorFinalUnitario'=> (float)$body['valorFinalUnitario'],
            'createdAt'         => new MongoDB\BSON\UTCDateTime(),
            'updatedAt'         => new MongoDB\BSON\UTCDateTime(),
        ];

        try {
            $result = $produtosCol->insertOne($doc);
            $doc['_id'] = (string)$result->getInsertedId();
            jsonResponse($doc, 201);
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao salvar produto: ' . $e->getMessage()], 500);
        }
    }

    // ATUALIZAR
    if ($method === 'PUT' && $id) {
        try {
            $filtro = ['_id' => new ObjectId($id)];
        } catch (Exception $e) {
            jsonResponse(['message' => 'ID de produto inválido.'], 400);
        }

        $atualizacoes = [];
        foreach (['quantidade','descricao','precoPacote','precoAnterior','loja','valorFinalUnitario'] as $c) {
            if (isset($body[$c])) {
                $atualizacoes[$c] = $c === 'descricao' || $c === 'loja'
                    ? (string)$body[$c]
                    : (float)$body[$c];
            }
        }
        if (!$atualizacoes) {
            jsonResponse(['message' => 'Nenhum campo para atualizar.'], 400);
        }
        $atualizacoes['updatedAt'] = new MongoDB\BSON\UTCDateTime();

        try {
            $result = $produtosCol->updateOne($filtro, ['$set' => $atualizacoes]);
            if ($result->getMatchedCount() === 0) {
                jsonResponse(['message' => 'Produto não encontrado.'], 404);
            }
            $atualizacoes['_id'] = $id;
            jsonResponse($atualizacoes);
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao atualizar produto: ' . $e->getMessage()], 500);
        }
    }

    // REMOVER
    if ($method === 'DELETE' && $id) {
        try {
            $objId = new ObjectId($id);
        } catch (Exception $e) {
            jsonResponse(['message' => 'ID de produto inválido.'], 400);
        }

        // Verifica se o produto está sendo usado em algum orçamento
        $uso = $orcamentosCol->findOne([
            'itens.materiais.materialId' => $objId
        ]);
        if ($uso) {
            jsonResponse(['message' => 'Não é possível excluir: produto usado em orçamentos.'], 400);
        }

        try {
            $result = $produtosCol->deleteOne(['_id' => $objId]);
            if ($result->getDeletedCount() === 0) {
                jsonResponse(['message' => 'Produto não encontrado.'], 404);
            }
            http_response_code(204);
            exit;
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao deletar produto: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['message' => 'Método não permitido para /produtos.'], 405);
}

// ----------------- ORÇAMENTOS -----------------
if ($recurso === 'orcamentos') {

    // LISTAR
    if ($method === 'GET') {
        try {
            $docs = $orcamentosCol->find([], ['sort' => ['createdAt' => -1]])->toArray();

            $orcamentos = array_map(function($o) {
                $o['_id']  = (string)$o['_id'];
                $o['itens'] = $o['itens'] ?? [];

                $o['itens'] = array_map(function($item) {
                    $item['_id'] = isset($item['_id']) ? (string)$item['_id'] : null;
                    $item['materiais'] = $item['materiais'] ?? [];
                    $item['materiais'] = array_map(function($m) {
                        $m['_id'] = isset($m['_id']) ? (string)$m['_id'] : null;
                        if (isset($m['material']['_id'])) {
                            $m['material']['_id'] = (string)$m['material']['_id'];
                        }
                        if (isset($m['materialId']) && $m['materialId'] instanceof ObjectId) {
                            $m['materialId'] = (string)$m['materialId'];
                        }
                        return $m;
                    }, $item['materiais']);
                    return $item;
                }, $o['itens']);

                return $o;
            }, $docs);

            jsonResponse($orcamentos);
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao buscar orçamentos: ' . $e->getMessage()], 500);
        }
    }

    // CRIAR
    if ($method === 'POST') {
        if (!isset($body['cliente'], $body['data'], $body['itens']) || !is_array($body['itens']) || !count($body['itens'])) {
            jsonResponse(['message' => 'Cliente, data e itens são obrigatórios.'], 400);
        }

        $doc = [
            'cliente'    => (string)$body['cliente'],
            'descricao'  => isset($body['descricao']) ? (string)$body['descricao'] : '',
            'data'       => (string)$body['data'],
            'itens'      => [],
            'createdAt'  => new MongoDB\BSON\UTCDateTime(),
            'updatedAt'  => new MongoDB\BSON\UTCDateTime(),
        ];

        foreach ($body['itens'] as $item) {
            $novoItem = [
                '_id'            => new ObjectId(),
                'nome'           => (string)($item['nome'] ?? ''),
                'quantidade'     => (float)($item['quantidade'] ?? 0),
                'percentualLucro'=> (float)($item['percentualLucro'] ?? 0),
                'custoUnitario'  => (float)($item['custoUnitario'] ?? 0),
                'custoTotal'     => (float)($item['custoTotal'] ?? 0),
                'materiais'      => [],
            ];

            foreach ($item['materiais'] ?? [] as $mat) {
                $materialIdStr = $mat['materialId'] ?? ($mat['material']['_id'] ?? null);
                $materialIdObj = null;
                if ($materialIdStr) {
                    try {
                        $materialIdObj = new ObjectId($materialIdStr);
                    } catch (Exception $e) {
                        // deixa null se não der
                    }
                }

                $novoItem['materiais'][] = [
                    '_id'            => new ObjectId(),
                    'materialId'     => $materialIdObj,
                    'quantidade'     => (float)($mat['quantidade'] ?? 0),
                    'folhasImpressas'=> (float)($mat['folhasImpressas'] ?? 0),
                    'custoMaterial'  => (float)($mat['custoMaterial'] ?? 0),
                    'custoFolhas'    => (float)($mat['custoFolhas'] ?? 0),
                    'custoTotal'     => (float)($mat['custoTotal'] ?? 0),
                    'material'       => isset($mat['material']) ? [
                        '_id'               => $materialIdObj,
                        'descricao'         => $mat['material']['descricao'] ?? '',
                        'valorFinalUnitario'=> (float)($mat['material']['valorFinalUnitario'] ?? 0),
                    ] : null
                ];
            }

            $doc['itens'][] = $novoItem;
        }

        try {
            $result = $orcamentosCol->insertOne($doc);
            $doc['_id'] = (string)$result->getInsertedId();
            // converte ObjectId aninhado para string
            $doc['itens'] = array_map(function($item) {
                $item['_id'] = (string)$item['_id'];
                $item['materiais'] = array_map(function($m) {
                    $m['_id'] = (string)$m['_id'];
                    if (isset($m['material']['_id']) && $m['material']['_id'] instanceof ObjectId) {
                        $m['material']['_id'] = (string)$m['material']['_id'];
                    }
                    if ($m['materialId'] instanceof ObjectId) {
                        $m['materialId'] = (string)$m['materialId'];
                    }
                    return $m;
                }, $item['materiais']);
                return $item;
            }, $doc['itens']);

            jsonResponse($doc, 201);
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao salvar orçamento: ' . $e->getMessage()], 500);
        }
    }

    // ATUALIZAR
    if ($method === 'PUT' && $id) {
        try {
            $filtro = ['_id' => new ObjectId($id)];
        } catch (Exception $e) {
            jsonResponse(['message' => 'ID de orçamento inválido.'], 400);
        }

        if (!isset($body['cliente'], $body['data'], $body['itens']) || !is_array($body['itens']) || !count($body['itens'])) {
            jsonResponse(['message' => 'Cliente, data e itens são obrigatórios.'], 400);
        }

        $novoDoc = [
            'cliente'   => (string)$body['cliente'],
            'descricao' => isset($body['descricao']) ? (string)$body['descricao'] : '',
            'data'      => (string)$body['data'],
            'itens'     => [],
            'updatedAt' => new MongoDB\BSON\UTCDateTime(),
        ];

        foreach ($body['itens'] as $item) {
            $novoItem = [
                '_id'            => new ObjectId(),
                'nome'           => (string)($item['nome'] ?? ''),
                'quantidade'     => (float)($item['quantidade'] ?? 0),
                'percentualLucro'=> (float)($item['percentualLucro'] ?? 0),
                'custoUnitario'  => (float)($item['custoUnitario'] ?? 0),
                'custoTotal'     => (float)($item['custoTotal'] ?? 0),
                'materiais'      => [],
            ];

            foreach ($item['materiais'] ?? [] as $mat) {
                $materialIdStr = $mat['materialId'] ?? ($mat['material']['_id'] ?? null);
                $materialIdObj = null;
                if ($materialIdStr) {
                    try {
                        $materialIdObj = new ObjectId($materialIdStr);
                    } catch (Exception $e) {
                        // mantém null
                    }
                }
                $novoItem['materiais'][] = [
                    '_id'            => new ObjectId(),
                    'materialId'     => $materialIdObj,
                    'quantidade'     => (float)($mat['quantidade'] ?? 0),
                    'folhasImpressas'=> (float)($mat['folhasImpressas'] ?? 0),
                    'custoMaterial'  => (float)($mat['custoMaterial'] ?? 0),
                    'custoFolhas'    => (float)($mat['custoFolhas'] ?? 0),
                    'custoTotal'     => (float)($mat['custoTotal'] ?? 0),
                    'material'       => isset($mat['material']) ? [
                        '_id'               => $materialIdObj,
                        'descricao'         => $mat['material']['descricao'] ?? '',
                        'valorFinalUnitario'=> (float)($mat['material']['valorFinalUnitario'] ?? 0),
                    ] : null
                ];
            }
            $novoDoc['itens'][] = $novoItem;
        }

        try {
            $result = $orcamentosCol->updateOne(
                $filtro,
                ['$set' => $novoDoc]
            );
            if ($result->getMatchedCount() === 0) {
                jsonResponse(['message' => 'Orçamento não encontrado.'], 404);
            }

            // converte ObjectIds para string antes de enviar pro front
            $novoDoc['_id'] = $id;
            $novoDoc['itens'] = array_map(function($item) {
                $item['_id'] = (string)$item['_id'];
                $item['materiais'] = array_map(function($m) {
                    $m['_id'] = (string)$m['_id'];
                    if (isset($m['material']['_id']) && $m['material']['_id'] instanceof ObjectId) {
                        $m['material']['_id'] = (string)$m['material']['_id'];
                    }
                    if ($m['materialId'] instanceof ObjectId) {
                        $m['materialId'] = (string)$m['materialId'];
                    }
                    return $m;
                }, $item['materiais']);
                return $item;
            }, $novoDoc['itens']);

            jsonResponse($novoDoc);
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao atualizar orçamento: ' . $e->getMessage()], 500);
        }
    }

    // REMOVER
    if ($method === 'DELETE' && $id) {
        try {
            $objId = new ObjectId($id);
        } catch (Exception $e) {
            jsonResponse(['message' => 'ID de orçamento inválido.'], 400);
        }

        try {
            $result = $orcamentosCol->deleteOne(['_id' => $objId]);
            if ($result->getDeletedCount() === 0) {
                jsonResponse(['message' => 'Orçamento não encontrado.'], 404);
            }
            http_response_code(204);
            exit;
        } catch (Exception $e) {
            jsonResponse(['message' => 'Erro ao deletar orçamento: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['message' => 'Método não permitido para /orcamentos.'], 405);
}

// ----------------- CASO NENHUM RECURSO BATA -----------------
jsonResponse(['message' => 'Recurso não encontrado. Use ?recurso=produtos ou ?recurso=orcamentos'], 404);
