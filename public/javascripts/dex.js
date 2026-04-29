// /dex page client. Wires a real Uniswap v4 swap UI against the protected
// and unprotected pools on Sepolia. ethers v6 via ESM CDN; no bundler.
//
// Pool selection toggles between two PoolKeys: protected (with the Immunity
// hook) vs unprotected (hooks = 0). The Swap button calls the canonical
// Sepolia V4 swap router. Hook reverts surface inline with a decoded reason.

import { ethers } from 'https://esm.sh/ethers@6.13.4';

const cfg = JSON.parse(document.getElementById('dex-config').textContent);

const HOOK_ZERO = '0x0000000000000000000000000000000000000000';

// Minimal ABIs we need on the client.
const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function mint(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
];

// V4 swap router: minimal ABI for the convenience helper used in the
// integration test runner.
const V4_ROUTER_ABI = [
    'function swapExactTokensForTokens(uint256 amountIn,uint256 amountOutMin,bool zeroForOne,(address,address,uint24,int24,address) poolKey,bytes hookData,address receiver,uint256 deadline) returns (int256[2])',
];

// V4 Quoter: read-only quote for the output amount of a swap. Public, no
// signer required, no gas. Used to populate the "To (estimated)" field as
// the user types, debounced.
const V4_QUOTER_ABI = [
    'function quoteExactInputSingle(((address,address,uint24,int24,address) poolKey,bool zeroForOne,uint256 exactAmount,bytes hookData)) returns (uint256 amountOut,uint256 gasEstimate)',
];

const HOOK_ERROR_ABI = [
    'error TokenBlocked(address token, bytes32 keccakId)',
    'error SenderBlocked(address sender, bytes32 keccakId)',
    'error OriginBlocked(address origin, bytes32 keccakId)',
];
const HOOK_ERROR_IFACE = new ethers.Interface(HOOK_ERROR_ABI);

const state = {
    pool: 'protected', // or 'unprotected'
    fromToken: 'A',    // 'A' (USDC-T) or 'B' (ETH-T)
    provider: null,
    signer: null,
    address: null,
};

// ---- DOM helpers -----------------------------------------------------------

function el(sel) { return document.querySelector(sel); }
function els(sel) { return Array.from(document.querySelectorAll(sel)); }
function setText(sel, text) { const e = el(sel); if (e) e.textContent = text; }
function shortAddr(a) { return a ? `${a.slice(0, 6)}...${a.slice(-4)}` : ''; }

function setResult(kind, text) {
    const r = el('[data-result]');
    if (!r) return;
    r.classList.remove('hidden');
    r.innerHTML = '';
    const map = {
        success: 'border-immune-500/40 bg-immune-500/5 text-immune-500',
        error:   'border-threat-500/40 bg-threat-500/5 text-threat-300',
        info:    'border-ink-line/60 bg-ink-sunken/40 text-fg-secondary',
    };
    r.className = `mt-5 border rounded-sm p-4 text-[12.5px] ${map[kind] || map.info}`;
    r.innerHTML = text;
}

function tokenAddr(side) {
    return side === 'A' ? cfg.tokenA : cfg.tokenB;
}
function tokenLabel(side) {
    return side === 'A' ? cfg.tokenALabel : cfg.tokenBLabel;
}
function otherSide(side) { return side === 'A' ? 'B' : 'A'; }

function activePoolKey() {
    return {
        currency0: cfg.currency0,
        currency1: cfg.currency1,
        fee: cfg.fee,
        tickSpacing: cfg.tickSpacing,
        hooks: state.pool === 'protected' ? cfg.hookAddress : HOOK_ZERO,
    };
}

// ---- Wallet ----------------------------------------------------------------

async function connect() {
    if (!window.ethereum) {
        setResult('error', 'No wallet detected. Install MetaMask and reload.');
        return;
    }
    try {
        const provider = new ethers.BrowserProvider(window.ethereum);
        await provider.send('eth_requestAccounts', []);
        const network = await provider.getNetwork();
        if (Number(network.chainId) !== cfg.chainId) {
            try {
                await window.ethereum.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: '0x' + cfg.chainId.toString(16) }],
                });
            } catch (err) {
                setResult('error', `Switch wallet to Sepolia (chain ${cfg.chainId}) and try again.`);
                return;
            }
        }
        state.provider = provider;
        state.signer = await provider.getSigner();
        state.address = await state.signer.getAddress();
        paintWalletStatus();
        await refreshBalances();
        paintSwapButton();
    } catch (err) {
        setResult('error', `Connect failed: ${err.message || err}`);
    }
}

function paintWalletStatus() {
    const dot = el('[data-wallet-status]');
    const lbl = el('[data-wallet-label]');
    if (state.address) {
        if (dot) dot.className = 'inline-flex h-2 w-2 rounded-full bg-immune-500 shrink-0';
        if (lbl) lbl.textContent = shortAddr(state.address);
        const btn = el('[data-connect]');
        if (btn) btn.textContent = 'Disconnect';
    } else {
        if (dot) dot.className = 'inline-flex h-2 w-2 rounded-full bg-fg-dim shrink-0';
        if (lbl) lbl.textContent = 'Wallet not connected';
        const btn = el('[data-connect]');
        if (btn) btn.textContent = 'Connect';
    }
}

// ---- Balances --------------------------------------------------------------

async function refreshBalances() {
    if (!state.address) return;
    try {
        const a = new ethers.Contract(cfg.tokenA, ERC20_ABI, state.provider);
        const b = new ethers.Contract(cfg.tokenB, ERC20_ABI, state.provider);
        const [balA, balB] = await Promise.all([
            a.balanceOf(state.address),
            b.balanceOf(state.address),
        ]);
        const fromAddr = tokenAddr(state.fromToken);
        const toAddr   = tokenAddr(otherSide(state.fromToken));
        const balFrom = fromAddr === cfg.tokenA ? balA : balB;
        const balTo   = toAddr === cfg.tokenA ? balA : balB;
        setText('[data-balance-from]', ethers.formatEther(balFrom).slice(0, 8));
        setText('[data-balance-to]',   ethers.formatEther(balTo).slice(0, 8));
    } catch (err) {
        // network blip; don't block the UI.
    }
}

// ---- Pool toggle + UI sync -------------------------------------------------

function paintPoolToggle() {
    els('[data-pool]').forEach((btn) => {
        const active = btn.dataset.pool === state.pool;
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
        btn.className = `flex-1 inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-sm font-mono text-[11px] uppercase tracking-widest2 transition-colors min-h-[44px] ${
            active
                ? 'bg-fg-primary text-ink-base'
                : 'text-fg-secondary hover:text-fg-primary'
        }`;
    });
    setText('[data-hook-display]', state.pool === 'protected'
        ? `${cfg.hookAddress.slice(0, 6)}...${cfg.hookAddress.slice(-4)}`
        : 'none');
    paintSwapButton();
}

function paintSwapButton() {
    const btn = el('[data-swap]');
    const lbl = el('[data-swap-label]');
    if (!btn || !lbl) return;
    if (!state.address) {
        btn.disabled = true;
        lbl.textContent = 'Connect to swap';
        return;
    }
    if (state.pool === 'unprotected' && !cfg.hasUnprotectedPool) {
        btn.disabled = true;
        lbl.textContent = 'Unprotected pool not deployed';
        return;
    }
    btn.disabled = false;
    lbl.textContent = `Swap on ${state.pool} pool`;
}

function flipDirection() {
    state.fromToken = otherSide(state.fromToken);
    setText('[data-token-from-label]', tokenLabel(state.fromToken));
    setText('[data-token-to-label]', tokenLabel(otherSide(state.fromToken)));
    refreshBalances();
    refreshQuote();
}

// ---- Quote (estimated output) ----------------------------------------------

let quoteTimer = null;

function refreshQuote() {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(runQuote, 250);
}

async function runQuote() {
    const inEl = el('[data-amount-in]');
    const outEl = el('[data-amount-out]');
    if (!outEl) return;
    const raw = inEl?.value?.trim();
    if (!raw || Number(raw) <= 0) {
        outEl.textContent = '0.0';
        return;
    }
    if (state.pool === 'unprotected' && !cfg.hasUnprotectedPool) {
        outEl.textContent = '~';
        return;
    }
    let amountIn;
    try { amountIn = ethers.parseEther(raw); } catch { outEl.textContent = '~'; return; }

    const fromAddr = tokenAddr(state.fromToken);
    const zeroForOne = fromAddr.toLowerCase() === cfg.currency0.toLowerCase();
    const poolKey = activePoolKey();

    try {
        // Read-only provider: a public RPC works for static calls. Reuses the
        // wallet provider when connected to keep RPC traffic in one place.
        const provider = state.provider ?? new ethers.JsonRpcProvider(cfg.rpcUrl);
        const quoter = new ethers.Contract(cfg.quoterAddress, V4_QUOTER_ABI, provider);
        const params = {
            poolKey: [poolKey.currency0, poolKey.currency1, poolKey.fee, poolKey.tickSpacing, poolKey.hooks],
            zeroForOne,
            exactAmount: amountIn,
            hookData: '0x',
        };
        const [amountOut] = await quoter.quoteExactInputSingle.staticCall([
            params.poolKey, params.zeroForOne, params.exactAmount, params.hookData,
        ]);
        const formatted = Number(ethers.formatEther(amountOut)).toFixed(6).replace(/\.?0+$/, '');
        outEl.textContent = formatted || '0';
        outEl.classList.remove('text-fg-tertiary');
        outEl.classList.add('text-fg-primary');
    } catch (err) {
        // Common cases: not enough liquidity, pool not initialized, hook
        // rejected the simulated swap. Fall back to a tilde so the UI stays
        // calm while the user adjusts the amount.
        outEl.textContent = '~';
        outEl.classList.add('text-fg-tertiary');
        outEl.classList.remove('text-fg-primary');
    }
}

// ---- Swap ------------------------------------------------------------------

function decodeRevert(err) {
    const data = err?.data ?? err?.info?.error?.data ?? err?.error?.data;
    if (typeof data === 'string' && data.startsWith('0x')) {
        try {
            const parsed = HOOK_ERROR_IFACE.parseError(data);
            if (parsed) {
                return `${parsed.name}(${parsed.args.map((a) => String(a)).join(', ')})`;
            }
        } catch {}
    }
    const msg = err?.shortMessage || err?.message || String(err);
    const m = msg.match(/(TokenBlocked|SenderBlocked|OriginBlocked)\([^)]*\)/);
    if (m) return m[0];
    return msg;
}

async function ensureApproval(tokenAddress, spender, amountWei) {
    const erc = new ethers.Contract(tokenAddress, ERC20_ABI, state.signer);
    const allowance = await erc.allowance(state.address, spender);
    if (allowance >= amountWei) return;
    setResult('info', 'Approving token transfer...');
    const tx = await erc.approve(spender, ethers.MaxUint256);
    await tx.wait();
}

async function swap() {
    if (!state.address) { await connect(); return; }
    const inEl = el('[data-amount-in]');
    const amountStr = inEl?.value?.trim();
    if (!amountStr || Number(amountStr) <= 0) {
        setResult('error', 'Enter an amount greater than zero.');
        return;
    }
    let amountIn;
    try { amountIn = ethers.parseEther(amountStr); }
    catch { setResult('error', 'Invalid amount.'); return; }

    const fromAddr = tokenAddr(state.fromToken);
    const toAddr   = tokenAddr(otherSide(state.fromToken));
    const zeroForOne = fromAddr.toLowerCase() === cfg.currency0.toLowerCase();
    const poolKey = activePoolKey();

    try {
        await ensureApproval(fromAddr, cfg.swapRouterAddress, amountIn);

        setResult('info', 'Submitting swap...');
        const router = new ethers.Contract(cfg.swapRouterAddress, V4_ROUTER_ABI, state.signer);
        const deadline = Math.floor(Date.now() / 1000) + 600;
        const tx = await router.swapExactTokensForTokens(
            amountIn,
            0n,
            zeroForOne,
            [poolKey.currency0, poolKey.currency1, poolKey.fee, poolKey.tickSpacing, poolKey.hooks],
            '0x',
            state.address,
            deadline,
        );
        const receipt = await tx.wait();
        setResult('success',
            `Swap confirmed on the <strong>${state.pool}</strong> pool. ` +
            `<a href="${cfg.blockExplorerUrl}/tx/${receipt.hash}" target="_blank" rel="noopener" class="underline underline-offset-4">View on Etherscan</a>.`
        );
        await refreshBalances();
    } catch (err) {
        const decoded = decodeRevert(err);
        const explanation = state.pool === 'protected'
            ? '<br><span class="opacity-80">This is the hook doing its job. Toggle to the unprotected pool to compare.</span>'
            : '';
        setResult('error', `Swap reverted: <code class="font-mono">${decoded}</code>${explanation}`);

        // Best-effort: report the failed tx hash so the antibody's
        // pool_reverts and value_protected counters increment. The backend
        // verifies on chain so a forged hash won't persist; the front-end
        // doesn't await this — it's pure side effect.
        const failedHash = err?.receipt?.hash ?? err?.transactionHash ?? err?.transaction?.hash;
        if (failedHash && state.pool === 'protected') {
            reportBlockedSwap(failedHash);
        }
    }
}

async function reportBlockedSwap(txHash) {
    try {
        const r = await fetch('/api/v1/dex/blocked-swap', {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify({ txHash, pool: state.pool }),
        });
        if (!r.ok) return;
        const data = await r.json();
        if (data?.ingested) {
            const result = el('[data-result]');
            if (result) {
                result.insertAdjacentHTML(
                    'beforeend',
                    `<div class="mt-2 pt-2 border-t border-current opacity-70 text-[11px]">Recorded $${Number(data.valueProtectedUsd).toLocaleString('en-US', { maximumFractionDigits: 2 })} as value protected.</div>`,
                );
            }
        }
    } catch {
        // silent — UX shouldn't suffer if telemetry POST flakes.
    }
}

// ---- Mint test tokens ------------------------------------------------------

async function mintTestTokens() {
    if (!state.address) { await connect(); return; }
    try {
        setResult('info', 'Minting 100 of each token...');
        const amount = ethers.parseEther('100');
        const a = new ethers.Contract(cfg.tokenA, ERC20_ABI, state.signer);
        const b = new ethers.Contract(cfg.tokenB, ERC20_ABI, state.signer);
        const tx1 = await a.mint(state.address, amount);
        await tx1.wait();
        const tx2 = await b.mint(state.address, amount);
        await tx2.wait();
        await refreshBalances();
        setResult('success', `Minted 100 ${cfg.tokenALabel} and 100 ${cfg.tokenBLabel} to your wallet.`);
    } catch (err) {
        setResult('error', `Mint failed: ${err.shortMessage || err.message || err}`);
    }
}

// ---- Bind ------------------------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
    paintPoolToggle();
    paintWalletStatus();
    paintSwapButton();

    el('[data-connect]')?.addEventListener('click', connect);
    el('[data-flip]')?.addEventListener('click', flipDirection);
    el('[data-swap]')?.addEventListener('click', swap);
    el('[data-mint]')?.addEventListener('click', mintTestTokens);
    el('[data-amount-in]')?.addEventListener('input', refreshQuote);

    els('[data-pool]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = btn.dataset.pool;
            if (!next) return;
            if (next === 'unprotected' && !cfg.hasUnprotectedPool) {
                setResult('info', 'The unprotected pool has not been seeded yet. Run the SeedUnprotectedPool script and reload.');
                return;
            }
            state.pool = next;
            paintPoolToggle();
            refreshQuote();
        });
    });
});
