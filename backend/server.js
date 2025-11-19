// backend/server.js
const express = require('express');
const mongoose = require('mongoose');
const cors = require('cors');
require('dotenv').config(); // Carrega variáveis de ambiente do .env

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors()); // Permite requisições de diferentes origens (seu frontend)
app.use(express.json()); // Permite que o Express leia JSON no corpo das requisições

// --- Conexão com MongoDB ---
const MONGODB_URI = process.env.MONGODB_URI;

mongoose.connect(MONGODB_URI)
    .then(() => console.log('Conectado ao MongoDB com sucesso!'))
    .catch(err => {
        console.error('Erro ao conectar ao MongoDB:', err);
        process.exit(1); // Sai do processo se não conseguir conectar ao DB
    });

// --- Definição dos Schemas e Modelos Mongoose ---

// Schema para Produtos (Materiais)
const produtoSchema = new mongoose.Schema({
    quantidade: { type: Number, required: true },
    descricao: { type: String, required: true },
    precoPacote: { type: Number, required: true },
    precoAnterior: { type: Number, default: 0 },
    loja: { type: String, required: true },
    valorFinalUnitario: { type: Number, required: true },
}, { timestamps: true });
const Produto = mongoose.model('Produto', produtoSchema);

// Schema para Materiais de cada Item de Orçamento
const orcamentoItemMaterialSchema = new mongoose.Schema({
    materialId: { type: mongoose.Schema.Types.ObjectId, ref: 'Produto', required: true },
    quantidade: { type: Number, required: true },
    folhasImpressas: { type: Number, default: 0 },
    custoMaterial: { type: Number, required: true },
    custoFolhas: { type: Number, required: true },
    custoTotal: { type: Number, required: true },
    // Campos adicionais para facilitar o frontend, não persistidos diretamente mas úteis
    material: { // Embed material details for easier retrieval
        _id: mongoose.Schema.Types.ObjectId,
        descricao: String,
        valorFinalUnitario: Number
    }
}, { _id: true }); // _id: true para que cada subdocumento tenha um ID único

// Schema para Itens de Orçamento (produtos dentro de um orçamento específico)
const orcamentoItemSchema = new mongoose.Schema({
    nome: { type: String, required: true },
    quantidade: { type: Number, required: true },
    percentualLucro: { type: Number, required: true },
    custoUnitario: { type: Number, required: true },
    custoTotal: { type: Number, required: true },
    materiais: [orcamentoItemMaterialSchema] // Array de subdocumentos
}, { _id: true }); // _id: true para que cada subdocumento tenha um ID único

// Schema para Orçamentos
const orcamentoSchema = new mongoose.Schema({
    cliente: { type: String, required: true },
    descricao: { type: String },
    data: { type: String, required: true }, // Armazenar como string formatada
    itens: [orcamentoItemSchema] // Array de subdocumentos
}, { timestamps: true });
const Orcamento = mongoose.model('Orcamento', orcamentoSchema);


// --- Rotas para Produtos (Materiais) ---
app.get('/produtos', async (req, res) => {
    try {
        const produtos = await Produto.find().sort({ descricao: 1 });
        res.json(produtos);
    } catch (error) {
        console.error('Erro ao buscar produtos:', error);
        res.status(500).json({ message: 'Erro ao buscar produtos' });
    }
});

app.post('/produtos', async (req, res) => {
    const { quantidade, descricao, precoPacote, precoAnterior, loja, valorFinalUnitario } = req.body;
    if (!quantidade || !descricao || !precoPacote || !loja || !valorFinalUnitario) {
        return res.status(400).json({ message: 'Todos os campos obrigatórios devem ser preenchidos.' });
    }
    try {
        const novoProduto = new Produto({ quantidade, descricao, precoPacote, precoAnterior, loja, valorFinalUnitario });
        await novoProduto.save();
        res.status(201).json(novoProduto);
    } catch (error) {
        console.error('Erro ao adicionar produto:', error);
        res.status(500).json({ message: 'Erro ao adicionar produto' });
    }
});

app.put('/produtos/:id', async (req, res) => {
    const { id } = req.params;
    const { quantidade, descricao, precoPacote, precoAnterior, loja, valorFinalUnitario } = req.body;
    if (!quantidade || !descricao || !precoPacote || !loja || !valorFinalUnitario) {
        return res.status(400).json({ message: 'Todos os campos obrigatórios devem ser preenchidos.' });
    }
    try {
        const produtoAtualizado = await Produto.findByIdAndUpdate(id, { quantidade, descricao, precoPacote, precoAnterior, loja, valorFinalUnitario }, { new: true });
        if (!produtoAtualizado) {
            return res.status(404).json({ message: 'Produto não encontrado.' });
        }
        res.json(produtoAtualizado);
    } catch (error) {
        console.error('Erro ao atualizar produto:', error);
        res.status(500).json({ message: 'Erro ao atualizar produto' });
    }
});

app.delete('/produtos/:id', async (req, res) => {
    const { id } = req.params;
    try {
        // Antes de deletar um produto, verificar se ele está sendo usado em algum orçamento
        const orcamentosComProduto = await Orcamento.find({
            'itens.materiais.materialId': id
        });

        if (orcamentosComProduto.length > 0) {
            return res.status(400).json({ message: 'Não é possível deletar este material, pois ele está sendo usado em um ou mais orçamentos.' });
        }

        const produtoDeletado = await Produto.findByIdAndDelete(id);
        if (!produtoDeletado) {
            return res.status(404).json({ message: 'Produto não encontrado.' });
        }
        res.status(204).send(); // No Content
    } catch (error) {
        console.error('Erro ao deletar produto:', error);
        res.status(500).json({ message: 'Erro ao deletar produto' });
    }
});

// --- Rotas para Orçamentos ---
app.get('/orcamentos', async (req, res) => {
    try {
        const orcamentos = await Orcamento.find().sort({ createdAt: -1 });
        res.json(orcamentos);
    } catch (error) {
        console.error('Erro ao buscar orçamentos:', error);
        res.status(500).json({ message: 'Erro ao buscar orçamentos' });
    }
});

app.post('/orcamentos', async (req, res) => {
    const { cliente, descricao, data, itens } = req.body;
    if (!cliente || !data || !itens || itens.length === 0) {
        return res.status(400).json({ message: 'Cliente, data e itens são obrigatórios.' });
    }
    try {
        const novoOrcamento = new Orcamento({ cliente, descricao, data, itens });
        await novoOrcamento.save();
        res.status(201).json(novoOrcamento);
    } catch (error) {
        console.error('Erro ao salvar orçamento:', error);
        res.status(500).json({ message: 'Erro ao salvar orçamento' });
    }
});

app.put('/orcamentos/:id', async (req, res) => {
    const { id } = req.params;
    const { cliente, descricao, data, itens } = req.body;
    if (!cliente || !data || !itens || itens.length === 0) {
        return res.status(400).json({ message: 'Cliente, data e itens são obrigatórios.' });
    }
    try {
        const orcamentoAtualizado = await Orcamento.findByIdAndUpdate(id, { cliente, descricao, data, itens }, { new: true });
        if (!orcamentoAtualizado) {
            return res.status(404).json({ message: 'Orçamento não encontrado.' });
        }
        res.json(orcamentoAtualizado);
    } catch (error) {
        console.error('Erro ao atualizar orçamento:', error);
        res.status(500).json({ message: 'Erro ao atualizar orçamento' });
    }
});

app.delete('/orcamentos/:id', async (req, res) => {
    const { id } = req.params;
    try {
        const orcamentoDeletado = await Orcamento.findByIdAndDelete(id);
        if (!orcamentoDeletado) {
            return res.status(404).json({ message: 'Orçamento não encontrado.' });
        }
        res.status(204).send(); // No Content
    } catch (error) {
        console.error('Erro ao deletar orçamento:', error);
        res.status(500).json({ message: 'Erro ao deletar orçamento' });
    }
});


// Servir arquivos estáticos do frontend (seu index.html)
// Se o frontend for um Static Site separado no Render, esta parte não é estritamente necessária
// Mas é útil para testar localmente ou se for um serviço monolítico
app.use(express.static('../public')); // Caminho relativo para a pasta public

// Iniciar o servidor
app.listen(PORT, () => {
    console.log(`Servidor Node.js rodando na porta ${PORT}`);
    console.log(`Acesse o frontend em http://localhost:${PORT}`);
});
