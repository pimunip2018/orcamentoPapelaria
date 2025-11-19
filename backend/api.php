<?php
// backend/api.php

// Carrega as dependências do Composer (incluindo o driver MongoDB)
require __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;

// --- Configurações de CORS e Headers ---
// Permite requisições de qualquer origem (ajuste em produção para o domínio do seu frontend)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Lida com requisições OPTIONS (preflight requests do CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- Funções Auxiliares ---
// Envia uma resposta JSON e encerra o script
function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Obtém o corpo da requisição JSON
function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// --- Conexão com MongoDB ---
try {
    // A URI do MongoDB será lida da variável de ambiente no Render
    // Para testar localmente, você pode definir MONGODB_URI diretamente aqui ou em um .env
    $mongoUri = $_ENV['MONGODB_URI'] ?? 'mongodb://localhost:27017/orcamento_papelaria';
    $client = new Client($mongoUri);
    $database = $client->selectDatabase('orcamento_papelaria'); // Nome do seu banco de dados

    $produtosCollection = $database->selectCollection('produtos');
    $orcamentosCollection = $database->selectCollection('orcamentos');

} catch (Exception $e) {
    jsonResponse(['message' => 'Erro de conexão com o MongoDB: ' . $e->getMessage()], 500);
}

// --- Roteamento da API ---
// A URL será algo como: https://seu-servico.onrender.com/produtos ou /orcamentos
// O Render irá direcionar a requisição para este api.php
// Usamos $_SERVER['REQUEST_URI'] para extrair o endpoint
$requestUri = $_SERVER['REQUEST_URI'];
// Remove a parte base da URL se houver (ex: /backend/api.php)
$path = parse_url($requestUri, PHP_URL_PATH);
$pathSegments = array_values(array_filter(explode('/', $path))); // Remove vazios e reindexa

// O endpoint principal (ex: 'produtos', 'orcamentos')
// Pega o último segmento da URL como endpoint
$endpoint = end($pathSegments); 

// O ID para operações PUT/DELETE/GET de um item específico
$id = $_GET['id'] ?? null; // ID pode vir como parâmetro de query string

$method = $_SERVER['REQUEST_METHOD'];
$input = getJsonBody();

// --- Rotas para Produtos (Materiais) ---
if ($endpoint === 'produtos') {
    if ($method === 'GET') {
        $produtos = $produtosCollection->find([], ['sort' => ['descricao' => 1]])->toArray();
        // Converte ObjectId para string para o frontend
        $produtos = array_map(function($p) {
            $p['_id'] = (string)$p['_id'];
            return $p;
        }, $produtos);
        jsonResponse($produtos);
    } elseif ($method === 'POST') {
        if (!isset($input['quantidade'], $input['descricao'], $input['precoPacote'], $input['loja'], $input['valorFinalUnitario'])) {
            jsonResponse(['message' => 'Campos obrigatórios faltando.'], 400);
        }
        $result = $produtosCollection->insertOne($input);
        jsonResponse(['_id' => (string)$result->getInsertedId(), ...$input], 201);
    } elseif ($method === 'PUT' && $id) {
        try {
            $result = $produtosCollection->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $input]
            );
            if ($result->getModifiedCount() === 0 && $result->getMatchedCount() === 0) {
                jsonResponse(['message' => 'Produto não encontrado ou nenhum dado alterado.'], 404);
            }
            jsonResponse(['_id' => $id, ...$input]);
        } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
            jsonResponse(['message' => 'ID inválido.'], 400);
        }
    } elseif ($method === 'DELETE' && $id) {
        try {
            // Antes de deletar um produto, verificar se ele está sendo usado em algum orçamento
            $orcamentosComProduto = $orcamentosCollection->findOne([
                'itens.materiais.materialId' => new ObjectId($id)
            ]);

            if ($orcamentosComProduto) {
                jsonResponse(['message' => 'Não é possível deletar este material, pois ele está sendo usado em um ou mais orçamentos.'], 400);
            }

            $result = $produtosCollection->deleteOne(['_id' => new ObjectId($id)]);
            if ($result->getDeletedCount() === 0) {
                jsonResponse(['message' => 'Produto não encontrado.'], 404);
            }
            http_response_code(204); // No Content
            exit;
        } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
            jsonResponse(['message' => 'ID inválido.'], 400);
        }
    } else {
        jsonResponse(['message' => 'Método não permitido para este endpoint.'], 405);
    }
} 
// --- Rotas para Orçamentos ---
elseif ($endpoint === 'orcamentos') {
    if ($method === 'GET') {
        $orcamentos = $orcamentosCollection->find([], ['sort' => ['createdAt' => -1]])->toArray();
        $orcamentos = array_map(function($o) {
            $o['_id'] = (string)$o['_id'];
            // Garante que 'itens' é um array, mesmo que vazio
            $o['itens'] = $o['itens'] ?? []; 
            // Converte _id dos itens e materiais para string
            $o['itens'] = array_map(function($item) {
                $item['_id'] = (string)$item['_id'];
                $item['materiais'] = array_map(function($mat) {
                    $mat['_id'] = (string)$mat['_id'];
                    // Garante que material._id também é string
                    if (isset($mat['material']['_id'])) {
                        $mat['material']['_id'] = (string)$mat['material']['_id'];
                    }
                    return $mat;
                }, $item['materiais'] ?? []);
                return $item;
            }, $o['itens']);
            return $o;
        }, $orcamentos);
        jsonResponse($orcamentos);
    } elseif ($method === 'POST') {
        if (!isset($input['cliente'], $input['data'], $input['itens']) || !is_array($input['itens'])) {
            jsonResponse(['message' => 'Cliente, data e itens são obrigatórios.'], 400);
        }
        // Adiciona um createdAt para ordenação
        $input['createdAt'] = new MongoDB\BSON\UTCDateTime();
        $result = $orcamentosCollection->insertOne($input);
        jsonResponse(['_id' => (string)$result->getInsertedId(), ...$input], 201);
    } elseif ($method === 'PUT' && $id) {
        try {
            // Adiciona um updatedAt
            $input['updatedAt'] = new MongoDB\BSON\UTCDateTime();
            $result = $orcamentosCollection->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $input]
            );
            if ($result->getModifiedCount() === 0 && $result->getMatchedCount() === 0) {
                jsonResponse(['message' => 'Orçamento não encontrado ou nenhum dado alterado.'], 404);
            }
            jsonResponse(['_id' => $id
