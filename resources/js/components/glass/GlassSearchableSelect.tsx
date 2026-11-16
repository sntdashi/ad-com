import { Check, ChevronDown, Search, X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type KeyboardEvent,
} from 'react';
import { createPortal } from 'react-dom';
import { VARIANTS } from '@/lib/motion';
import { cn } from '@/lib/utils';

export type SearchableOption = {
    value: string;
    label: string;
    description?: string;
};

type GlassSearchableSelectProps = {
    options: SearchableOption[];
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    disabled?: boolean;
    className?: string;
    id?: string;
    'aria-label'?: string;
};

type MenuPosition = {
    top: number;
    left: number;
    width: number;
    maxHeight: number;
    placement: 'bottom' | 'top';
};

export function GlassSearchableSelect({
    options,
    value,
    onChange,
    placeholder = 'Pilih opsi...',
    searchPlaceholder = 'Cari nama atau ID...',
    emptyText = 'Tidak ada hasil yang cocok.',
    disabled = false,
    className,
    id,
    'aria-label': ariaLabel,
}: GlassSearchableSelectProps) {
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);

    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const [position, setPosition] = useState<MenuPosition | null>(null);

    const selectedOption = useMemo(
        () => options.find((opt) => opt.value === value) ?? null,
        [options, value],
    );

    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) {
            return options;
        }

        return options.filter(
            (opt) =>
                opt.label.toLowerCase().includes(needle) ||
                opt.value.toLowerCase().includes(needle) ||
                (opt.description?.toLowerCase().includes(needle) ?? false),
        );
    }, [options, query]);

    const updatePosition = useCallback(() => {
        const trigger = triggerRef.current;
        if (!trigger) {
            return;
        }

        const rect = trigger.getBoundingClientRect();
        const viewportH = window.innerHeight;
        const viewportW = window.innerWidth;
        const margin = 10;
        const desiredMaxHeight = 350;

        const spaceBelow = viewportH - rect.bottom - margin;
        const spaceAbove = rect.top - margin;
        const openTop = spaceBelow < 240 && spaceAbove > spaceBelow;
        const available = openTop ? spaceAbove : spaceBelow;
        const maxHeight = Math.max(180, Math.min(desiredMaxHeight, available));

        const width = Math.min(
            Math.max(rect.width, 280),
            viewportW - margin * 2,
        );
        const left = Math.min(
            Math.max(margin, rect.left),
            viewportW - width - margin,
        );
        const top = openTop ? rect.top - 6 : rect.bottom + 6;

        setPosition({
            top,
            left,
            width,
            maxHeight,
            placement: openTop ? 'top' : 'bottom',
        });
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        updatePosition();
        const raf = requestAnimationFrame(() => {
            searchInputRef.current?.focus();
        });

        const handleReposition = () => updatePosition();
        const handleClickOutside = (event: MouseEvent) => {
            const target = event.target as Node;
            if (
                triggerRef.current?.contains(target) ||
                menuRef.current?.contains(target)
            ) {
                return;
            }
            setOpen(false);
        };
        const handleEscape = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                triggerRef.current?.focus();
            }
        };

        window.addEventListener('resize', handleReposition);
        window.addEventListener('scroll', handleReposition, true);
        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('keydown', handleEscape);

        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', handleReposition);
            window.removeEventListener('scroll', handleReposition, true);
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleEscape);
        };
    }, [open, updatePosition]);

    function handleSelect(option: SearchableOption) {
        onChange(option.value);
        setOpen(false);
        setQuery('');
        triggerRef.current?.focus();
    }

    function handleKeyDown(event: KeyboardEvent<HTMLElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (!open) {
                setOpen(true);
                return;
            }
            setActiveIndex((prev) =>
                filteredOptions.length === 0
                    ? 0
                    : (prev + 1) % filteredOptions.length,
            );
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) {
                setOpen(true);
                return;
            }
            setActiveIndex((prev) =>
                filteredOptions.length === 0
                    ? 0
                    : (prev - 1 + filteredOptions.length) %
                      filteredOptions.length,
            );
        } else if (event.key === 'Enter' && open) {
            event.preventDefault();
            const picked = filteredOptions[activeIndex];
            if (picked) {
                handleSelect(picked);
            }
        }
    }

    return (
        <>
            <button
                ref={triggerRef}
                id={id}
                type="button"
                disabled={disabled}
                aria-label={ariaLabel}
                aria-haspopup="listbox"
                aria-expanded={open}
                onClick={() => {
                    setOpen((prev) => !prev);
                    setActiveIndex(0);
                }}
                onKeyDown={handleKeyDown}
                className={cn(
                    'glass-pill flex min-h-10 w-full max-w-full min-w-0 items-center justify-between gap-2 overflow-hidden rounded-xl px-3.5 py-2 text-left text-sm transition-all',
                    'focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:outline-none',
                    disabled && 'cursor-not-allowed opacity-55',
                    className,
                )}
            >
                <span className="min-w-0 flex-1 overflow-hidden">
                    {selectedOption ? (
                        <span className="text-foreground block truncate font-semibold">
                            {selectedOption.label}
                            {selectedOption.label !== selectedOption.value && (
                                <span className="text-muted-foreground ml-1.5 font-mono text-xs font-normal">
                                    ({selectedOption.value})
                                </span>
                            )}
                        </span>
                    ) : (
                        <span className="text-muted-foreground block truncate">
                            {placeholder}
                        </span>
                    )}
                </span>
                <ChevronDown
                    className={cn(
                        'text-muted-foreground size-4 shrink-0 transition-transform duration-200',
                        open && 'rotate-180',
                    )}
                />
            </button>

            {typeof document !== 'undefined' &&
                createPortal(
                    <AnimatePresence>
                        {open && position && (
                            <motion.div
                                ref={menuRef}
                                role="listbox"
                                variants={VARIANTS.popover}
                                initial="initial"
                                animate="animate"
                                exit="exit"
                                onKeyDown={handleKeyDown}
                                style={{
                                    position: 'fixed',
                                    top: position.top,
                                    left: position.left,
                                    width: position.width,
                                    maxHeight: position.maxHeight,
                                    transformOrigin:
                                        position.placement === 'top'
                                            ? 'bottom center'
                                            : 'top center',
                                    translateY:
                                        position.placement === 'top'
                                            ? '-100%'
                                            : '0%',
                                    zIndex: 120,
                                }}
                                className="glass-popover flex flex-col overflow-hidden rounded-2xl"
                            >
                                <div className="border-b border-black/[0.06] p-2.5 dark:border-white/[0.08]">
                                    <div className="relative flex items-center">
                                        <Search className="text-muted-foreground pointer-events-none absolute left-3 size-3.5" />
                                        <input
                                            ref={searchInputRef}
                                            type="text"
                                            value={query}
                                            onChange={(e) => {
                                                setQuery(e.target.value);
                                                setActiveIndex(0);
                                            }}
                                            placeholder={searchPlaceholder}
                                            className="text-foreground placeholder:text-muted-foreground w-full rounded-xl bg-black/[0.04] py-2 pr-8 pl-8 text-xs font-medium focus:outline-none dark:bg-white/[0.06]"
                                        />
                                        {query && (
                                            <button
                                                type="button"
                                                aria-label="Hapus pencarian"
                                                onClick={() => {
                                                    setQuery('');
                                                    setActiveIndex(0);
                                                    searchInputRef.current?.focus();
                                                }}
                                                className="text-muted-foreground hover:text-foreground absolute right-2.5"
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        )}
                                    </div>
                                </div>

                                <div className="min-h-0 flex-1 overflow-y-auto p-1.5">
                                    {filteredOptions.length === 0 ? (
                                        <p className="text-muted-foreground px-3 py-6 text-center text-xs">
                                            {emptyText}
                                        </p>
                                    ) : (
                                        filteredOptions.map((option, idx) => {
                                            const isSelected =
                                                option.value === value;
                                            const isHighlighted =
                                                idx === activeIndex;

                                            return (
                                                <button
                                                    key={option.value}
                                                    type="button"
                                                    role="option"
                                                    aria-selected={isSelected}
                                                    onMouseEnter={() =>
                                                        setActiveIndex(idx)
                                                    }
                                                    onClick={() =>
                                                        handleSelect(option)
                                                    }
                                                    className={cn(
                                                        'flex w-full items-start justify-between gap-2 rounded-xl px-3 py-2 text-left text-xs transition-colors',
                                                        isHighlighted &&
                                                            'bg-black/[0.05] dark:bg-white/[0.08]',
                                                        isSelected &&
                                                            'bg-primary/10 text-primary dark:bg-primary/20',
                                                    )}
                                                >
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block font-semibold break-words">
                                                            {option.label}
                                                        </span>
                                                        <span className="text-muted-foreground mt-0.5 block font-mono text-[11px] break-all">
                                                            {option.value}
                                                        </span>
                                                    </span>
                                                    {isSelected && (
                                                        <Check className="mt-0.5 size-3.5 shrink-0" />
                                                    )}
                                                </button>
                                            );
                                        })
                                    )}
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>,
                    document.body,
                )}
        </>
    );
}
