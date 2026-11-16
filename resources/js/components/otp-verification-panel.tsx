import { LoaderCircle, RefreshCw, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    issue as issueOtp,
    resend as resendOtp,
    verify as verifyOtp,
} from '@/routes/otp';

export type OtpChallengeState = {
    challenge_id: string;
    purpose: string;
    length: number;
    expires_at: string;
    resend_available_at: string;
    attempts_remaining: number;
    max_attempts: number;
    resends_remaining: number;
    max_resends: number;
};

type Props = {
    purpose:
        | 'registration_phone'
        | 'password_reset'
        | 'password_change'
        | 'sensitive_action'
        | 'phone_change';
    phone?: string;
    onVerified?: (challengeId: string) => void;
};

export default function OtpVerificationPanel({
    purpose,
    phone,
    onVerified,
}: Props) {
    const [challenge, setChallenge] = useState<OtpChallengeState | null>(null);
    const [code, setCode] = useState('');
    const [loadingIssue, setLoadingIssue] = useState(false);
    const [loadingVerify, setLoadingVerify] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [verified, setVerified] = useState(false);
    const [nowMs, setNowMs] = useState(() => Date.now());

    useEffect(() => {
        if (!challenge || verified) {
            return;
        }
        const timer = window.setInterval(() => setNowMs(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [challenge, verified]);

    const expiresInSeconds = challenge
        ? Math.max(
              0,
              Math.ceil((Date.parse(challenge.expires_at) - nowMs) / 1000),
          )
        : 0;
    const resendInSeconds = challenge
        ? Math.max(
              0,
              Math.ceil(
                  (Date.parse(challenge.resend_available_at) - nowMs) / 1000,
              ),
          )
        : 0;

    async function handleIssue() {
        setLoadingIssue(true);
        setError(null);
        try {
            const response = await fetch(issueOtp.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                body: JSON.stringify({ purpose, phone }),
            });
            const data = await response.json();
            if (!response.ok) {
                setError(firstError(data) ?? 'Gagal mengirim OTP.');
                return;
            }
            setChallenge(data.challenge);
            setNowMs(Date.now());
            setVerified(false);
            setCode('');
        } finally {
            setLoadingIssue(false);
        }
    }

    async function handleResend() {
        if (!challenge || resendInSeconds > 0) {
            return;
        }
        setLoadingIssue(true);
        setError(null);
        try {
            const response = await fetch(
                resendOtp.url({ challengeId: challenge.challenge_id }),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readCsrfToken(),
                    },
                },
            );
            const data = await response.json();
            if (!response.ok) {
                setError(firstError(data) ?? 'Gagal mengirim ulang OTP.');
                return;
            }
            setChallenge(data.challenge);
            setNowMs(Date.now());
            setCode('');
        } finally {
            setLoadingIssue(false);
        }
    }

    async function handleVerify() {
        if (!challenge || expiresInSeconds <= 0) {
            return;
        }
        setLoadingVerify(true);
        setError(null);
        try {
            const response = await fetch(
                verifyOtp.url({ challengeId: challenge.challenge_id }),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': readCsrfToken(),
                    },
                    body: JSON.stringify({ purpose, code }),
                },
            );
            const data = await response.json();
            if (!response.ok) {
                setError(firstError(data) ?? 'Kode OTP tidak valid.');
                setChallenge((prev) =>
                    prev
                        ? {
                              ...prev,
                              attempts_remaining: Math.max(
                                  0,
                                  prev.attempts_remaining - 1,
                              ),
                          }
                        : prev,
                );
                return;
            }
            setVerified(true);
            onVerified?.(challenge.challenge_id);
        } finally {
            setLoadingVerify(false);
        }
    }

    return (
        <div className="liquid-control space-y-3 rounded-2xl p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-foreground text-sm font-bold">
                        Verifikasi OTP WhatsApp
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {challenge
                            ? expiresInSeconds > 0
                                ? `Berlaku ${Math.floor(expiresInSeconds / 60)}:${String(expiresInSeconds % 60).padStart(2, '0')} · Sisa percobaan: ${challenge.attempts_remaining}/${challenge.max_attempts}`
                                : 'Kode OTP sudah kedaluwarsa. Kirim ulang kode baru.'
                            : 'Kirim kode verifikasi ke WhatsApp Anda.'}
                    </p>
                </div>
                {!challenge ? (
                    <Button
                        type="button"
                        size="sm"
                        disabled={loadingIssue}
                        onClick={handleIssue}
                    >
                        {loadingIssue && (
                            <LoaderCircle className="size-3.5 animate-spin" />
                        )}
                        Kirim OTP
                    </Button>
                ) : (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={
                            loadingIssue ||
                            resendInSeconds > 0 ||
                            challenge.resends_remaining <= 0 ||
                            verified
                        }
                        onClick={handleResend}
                    >
                        <RefreshCw className="size-3.5" />
                        {resendInSeconds > 0
                            ? `Kirim ulang (${resendInSeconds}d)`
                            : `Kirim ulang (${challenge.resends_remaining} tersisa)`}
                    </Button>
                )}
            </div>

            {challenge && !verified && (
                <div className="flex flex-wrap items-end gap-2">
                    <div className="grid flex-1 gap-1.5">
                        <Label htmlFor="otp_code_input">
                            Kode OTP ({challenge.length} digit)
                        </Label>
                        <Input
                            id="otp_code_input"
                            inputMode="numeric"
                            maxLength={challenge.length}
                            value={code}
                            onChange={(e) =>
                                setCode(e.target.value.replace(/\D+/g, ''))
                            }
                            placeholder={'0'.repeat(challenge.length)}
                        />
                    </div>
                    <Button
                        type="button"
                        disabled={
                            loadingVerify ||
                            expiresInSeconds <= 0 ||
                            code.length < challenge.length
                        }
                        onClick={handleVerify}
                    >
                        {loadingVerify && (
                            <LoaderCircle className="size-3.5 animate-spin" />
                        )}
                        Verifikasi
                    </Button>
                </div>
            )}

            {verified && (
                <p className="flex items-center gap-1.5 text-xs font-bold text-[var(--status-success)]">
                    <ShieldCheck className="size-4" /> Nomor WhatsApp
                    terverifikasi.
                </p>
            )}

            {error && (
                <p className="text-xs font-bold text-[var(--status-danger)]">
                    {error}
                </p>
            )}
        </div>
    );
}

function readCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function firstError(payload: {
    message?: string;
    errors?: Record<string, string[]>;
}): string | null {
    if (payload.errors) {
        const firstKey = Object.keys(payload.errors)[0];
        if (firstKey && payload.errors[firstKey]?.[0]) {
            return payload.errors[firstKey][0];
        }
    }
    return payload.message ?? null;
}
