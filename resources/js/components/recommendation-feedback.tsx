import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Eye, Flag, X } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError } from '../lib/api';
import type { FeedbackAction } from '../lib/types';
import { cn } from '../lib/utils';
import { Button } from './ui/button';

const actions = [
    { action: 'interested', label: 'Interested', icon: Check },
    { action: 'not_for_me', label: 'Not for me', icon: X },
    { action: 'already_know', label: 'Already know', icon: Eye },
    { action: 'wrong_match', label: 'Wrong match', icon: Flag },
] as const;

export function RecommendationFeedback({ itemId, entityId, initialAction = null }: { itemId: string; entityId: string; initialAction?: FeedbackAction | null }) {
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<FeedbackAction | null>(initialAction);
    const feedback = useMutation({
        mutationFn: (action: FeedbackAction) => api.recommendationItemFeedback(itemId, action),
        onSuccess: (_, action) => {
            setSelected(action);
            return Promise.all([
                queryClient.invalidateQueries({ queryKey: ['home'] }),
                queryClient.invalidateQueries({ queryKey: ['beyond'] }),
                queryClient.invalidateQueries({ queryKey: ['album', entityId] }),
            ]);
        },
        onError: (error) => {
            if (error instanceof ApiError && error.status === 409) queryClient.invalidateQueries({ queryKey: ['home'] });
        },
    });
    const clear = useMutation({
        mutationFn: () => api.clearRecommendationFeedback(entityId),
        onSuccess: () => {
            setSelected(null);
            return Promise.all([
                queryClient.invalidateQueries({ queryKey: ['home'] }),
                queryClient.invalidateQueries({ queryKey: ['beyond'] }),
                queryClient.invalidateQueries({ queryKey: ['album', entityId] }),
            ]);
        },
    });
    const suppressiveSelection = selected !== null && selected !== 'interested';

    return (
        <div aria-label="Recommendation feedback">
            <div className="flex flex-wrap gap-2">
                {actions.map(({ action, label, icon: Icon }) => (
                    <Button key={action} type="button" variant="secondary" size="sm" aria-pressed={selected === action} disabled={feedback.isPending || clear.isPending} onClick={() => feedback.mutate(action)} className={cn(selected === action && 'border-cobalt bg-cobalt/10 text-cobalt')}>
                        <Icon className="size-3" aria-hidden="true" />{label}
                    </Button>
                ))}
            </div>
            {selected === 'interested' && <p className="mt-2 text-xs text-cobalt" role="status">Marked as interested.</p>}
            {suppressiveSelection && <div className="mt-2 flex items-center gap-2 text-xs text-fog" role="status"><span>Saved. This album will be hidden.</span><button type="button" className="font-bold text-cobalt underline underline-offset-2" disabled={clear.isPending} onClick={() => clear.mutate()}>Undo</button></div>}
            {(feedback.isError || clear.isError) && <p className="mt-2 text-xs text-coral-deep" role="alert">Feedback could not be saved.</p>}
        </div>
    );
}
