import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { ApiError, request, errorMessage } from './api';
import { endpoints as api } from './endpoints';
import { Button, Chip, Field, Hero, Notice, Panel, styles, color } from './ui';

type ProposedChange = {
    label: string;
    before?: string | null;
    after: string;
};

type FinancialImpact = {
    label: string;
    amount: number | null;
    formatted: string | null;
    breakdown?: Array<{ label: string; value: string }>;
};

type ActionPreview = {
    title?: string;
    target?: string;
    proposed_changes?: ProposedChange[];
    financial_impact?: FinancialImpact | null;
    warnings?: string[];
    summary_text?: string;
};

export type MobileActionProposal = {
    action_id: string;
    action_name: string;
    action_label: string;
    status: string;
    payload_hash: string;
    expires_at: string;
    ttl_seconds?: number;
    preview?: ActionPreview | null;
    result_reference?: string | null;
    result_summary?: Record<string, unknown> | null;
    failure_reason?: string | null;
    confirm_url?: string;
    cancel_url?: string;
};

type Turn = {
    role: 'user' | 'assistant';
    content: string;
    proposal?: MobileActionProposal | null;
    actionFeedback?: string | null;
};

type Reply = {
    reply: string;
    mode: string;
    suggestions: string[];
    action_proposal?: MobileActionProposal | null;
};

type ActionMutationReply = {
    status: string;
    message: string;
    action_proposal: MobileActionProposal | null;
};

function proposalStatusLabel(status: string, expired: boolean): string {
    if (expired || status === 'expired') {
        return 'Kedaluwarsa';
    }
    switch (status) {
        case 'awaiting_confirmation':
            return 'Menunggu Konfirmasi';
        case 'executed':
            return 'Berhasil Dieksekusi';
        case 'cancelled':
            return 'Dibatalkan';
        case 'denied':
            return 'Ditolak';
        case 'failed':
            return 'Gagal';
        default:
            return status;
    }
}

export function MobileActionProposalCard({
    proposal,
    nowMs,
    acting,
    disabled,
    feedback,
    onConfirm,
    onCancel,
}: {
    proposal: MobileActionProposal;
    nowMs: number;
    acting: boolean;
    disabled: boolean;
    feedback?: string | null;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    const expiresMs = Date.parse(proposal.expires_at);
    const isExpired =
        proposal.status === 'expired' ||
        (proposal.status === 'awaiting_confirmation' &&
            Number.isFinite(expiresMs) &&
            expiresMs <= nowMs);
    const remainingSeconds = Number.isFinite(expiresMs)
        ? Math.max(0, Math.floor((expiresMs - nowMs) / 1000))
        : 0;
    const isPending = proposal.status === 'awaiting_confirmation' && !isExpired;

    return (
        <Panel>
            <View
                style={{
                    flexDirection: 'row',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    gap: 8,
                    flexWrap: 'wrap',
                }}
            >
                <Text style={styles.label}>
                    {proposal.preview?.title || proposal.action_label}
                </Text>
                <Chip>{proposalStatusLabel(proposal.status, isExpired)}</Chip>
            </View>

            {proposal.preview?.target && (
                <Text style={[styles.body, { color: color.ink }]}>
                    Target: {proposal.preview.target}
                </Text>
            )}

            {proposal.preview?.proposed_changes?.map((change, changeIdx) => (
                <View key={changeIdx} style={{ gap: 2 }}>
                    <Text style={styles.micro}>{change.label}</Text>
                    <Text style={[styles.body, { color: color.ink }]}>
                        {change.before
                            ? `${change.before} → ${change.after}`
                            : change.after}
                    </Text>
                </View>
            ))}

            {proposal.preview?.financial_impact && (
                <View style={{ gap: 2 }}>
                    <Text style={styles.micro}>
                        {proposal.preview.financial_impact.label}
                    </Text>
                    <Text
                        style={[
                            styles.body,
                            { color: color.deep, fontWeight: '700' },
                        ]}
                    >
                        {proposal.preview.financial_impact.formatted ?? 'Rp 0'}
                    </Text>
                </View>
            )}

            {proposal.preview?.warnings?.map((warning, warnIdx) => (
                <Text
                    key={warnIdx}
                    style={[styles.micro, { color: color.muted }]}
                >
                    • {warning}
                </Text>
            ))}

            {isPending && (
                <Text style={[styles.micro, { color: color.muted }]}>
                    Konfirmasi berlaku {remainingSeconds} detik lagi.
                </Text>
            )}

            {isExpired && (
                <Notice message="Batas waktu konfirmasi proposal ini sudah berakhir. Ajukan ulang permintaan ke ADI jika masih ingin melanjutkan." />
            )}

            {proposal.result_reference && (
                <Text
                    style={[
                        styles.body,
                        { color: color.deep, fontWeight: '700' },
                    ]}
                >
                    Referensi Hasil: {proposal.result_reference}
                </Text>
            )}

            {feedback ? <Notice message={feedback} /> : null}

            {isPending && (
                <View style={{ gap: 8, marginTop: 4 }}>
                    <Button
                        label="Konfirmasi & Jalankan"
                        busy={acting}
                        disabled={disabled}
                        onPress={onConfirm}
                    />
                    <Button
                        secondary
                        label="Batalkan"
                        disabled={disabled}
                        onPress={onCancel}
                    />
                </View>
            )}
        </Panel>
    );
}

export function AssistantScreen() {
    const [history, setHistory] = useState<Turn[]>([]);
    const [message, setMessage] = useState('');
    const [suggestions, setSuggestions] = useState([
        'Wi-Fi sering putus',
        'Mau pasang CCTV',
        'Komputer terasa lambat',
    ]);
    const [busy, setBusy] = useState(false);
    const [actingId, setActingId] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [mode, setMode] = useState('');
    const [nowMs, setNowMs] = useState(() => Date.now());

    const hasPendingProposal = history.some(
        (turn) => turn.proposal?.status === 'awaiting_confirmation',
    );

    useEffect(() => {
        if (!hasPendingProposal) {
            return;
        }
        const timer = setInterval(() => {
            setNowMs(Date.now());
        }, 1000);
        return () => clearInterval(timer);
    }, [hasPendingProposal]);

    async function send(text: string) {
        if (!text.trim() || busy) return;
        setBusy(true);
        setNotice('');
        try {
            const result = await request<Reply>(api.assistant.store.url(), {
                method: 'POST',
                body: {
                    message: text,
                    messages: history.slice(-8).map((turn) => ({
                        role: turn.role,
                        content: turn.content.slice(0, 1200),
                    })),
                },
            });
            setHistory((previous) => [
                ...previous,
                { role: 'user', content: text },
                {
                    role: 'assistant',
                    content: result.reply,
                    proposal: result.action_proposal ?? null,
                },
            ]);
            setSuggestions(result.suggestions || []);
            setMessage('');
            setMode(result.mode);
        } catch (err) {
            setNotice(errorMessage(err));
        } finally {
            setBusy(false);
        }
    }

    function patchTurnProposal(
        turnIndex: number,
        updater: (turn: Turn) => Turn,
    ) {
        setHistory((previous) =>
            previous.map((item, idx) =>
                idx === turnIndex ? updater(item) : item,
            ),
        );
    }

    async function confirmProposal(
        turnIndex: number,
        proposal: MobileActionProposal,
    ) {
        setActingId(proposal.action_id);
        setNotice('');
        try {
            const url =
                proposal.confirm_url ||
                api.assistantActions.confirm.url({
                    proposal: proposal.action_id,
                });
            const result = await request<ActionMutationReply>(url, {
                method: 'POST',
                body: { payload_hash: proposal.payload_hash },
            });
            patchTurnProposal(turnIndex, (turn) => ({
                ...turn,
                proposal: result.action_proposal ?? {
                    ...proposal,
                    status: result.status,
                },
                actionFeedback: result.message,
            }));
        } catch (err) {
            const feedback = errorMessage(err);
            const expired = err instanceof ApiError && err.status === 410;
            patchTurnProposal(turnIndex, (turn) => ({
                ...turn,
                proposal: turn.proposal
                    ? {
                          ...turn.proposal,
                          status: expired ? 'expired' : turn.proposal.status,
                      }
                    : null,
                actionFeedback: feedback,
            }));
            setNotice(feedback);
        } finally {
            setActingId(null);
        }
    }

    async function cancelProposal(
        turnIndex: number,
        proposal: MobileActionProposal,
    ) {
        setActingId(proposal.action_id);
        setNotice('');
        try {
            const url =
                proposal.cancel_url ||
                api.assistantActions.cancel.url({
                    proposal: proposal.action_id,
                });
            const result = await request<ActionMutationReply>(url, {
                method: 'POST',
            });
            patchTurnProposal(turnIndex, (turn) => ({
                ...turn,
                proposal: result.action_proposal ?? {
                    ...proposal,
                    status: result.status,
                },
                actionFeedback: result.message,
            }));
        } catch (err) {
            const feedback = errorMessage(err);
            patchTurnProposal(turnIndex, (turn) => ({
                ...turn,
                actionFeedback: feedback,
            }));
            setNotice(feedback);
        } finally {
            setActingId(null);
        }
    }

    return (
        <>
            <Hero
                eyebrow="ASISTEN LAYANAN AD COM"
                title="Tanya ADI"
                description="Ceritakan kendalamu. ADI membantu memberi arahan awal sebelum tim teknisi memeriksa langsung."
            />
            <Notice message="Jangan kirim kata sandi, OTP, data pribadi sensitif, atau membongkar perangkat bertegangan. Jawaban ADI bukan diagnosis pasti maupun penawaran harga final." />
            {mode === 'guide' && (
                <Notice message="Jawaban ini menggunakan panduan lokal AD Com, bukan model AI." />
            )}
            {history.map((turn, index) => (
                <View
                    key={index}
                    style={{
                        padding: 18,
                        borderRadius: 22,
                        backgroundColor:
                            turn.role === 'user' ? color.rose : 'white',
                        gap: 10,
                    }}
                >
                    <Text style={styles.label}>
                        {turn.role === 'user' ? 'Kamu' : 'ADI'}
                    </Text>
                    <Text
                        selectable
                        style={[styles.body, { color: color.ink }]}
                    >
                        {turn.content}
                    </Text>
                    {turn.proposal ? (
                        <MobileActionProposalCard
                            proposal={turn.proposal}
                            nowMs={nowMs}
                            acting={actingId === turn.proposal.action_id}
                            disabled={actingId !== null || busy}
                            feedback={turn.actionFeedback}
                            onConfirm={() =>
                                void confirmProposal(index, turn.proposal!)
                            }
                            onCancel={() =>
                                void cancelProposal(index, turn.proposal!)
                            }
                        />
                    ) : null}
                </View>
            ))}
            <Panel>
                <Field
                    label="Apa yang ingin kamu tanyakan?"
                    multiline
                    value={message}
                    onChangeText={setMessage}
                    maxLength={1200}
                />
                <Notice message={notice} />
                <Button
                    label="Tanya ADI"
                    disabled={message.trim().length < 2}
                    busy={busy}
                    onPress={() => void send(message)}
                />
                {suggestions.map((suggestion) => (
                    <Button
                        secondary
                        key={suggestion}
                        disabled={busy}
                        label={suggestion}
                        onPress={() => void send(suggestion)}
                    />
                ))}
            </Panel>
        </>
    );
}
