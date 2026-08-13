import { ArrowLeft } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Button } from '../components/ui/button';

export function NotFoundPage() {
    return (
        <div className="grid min-h-[65vh] place-items-center">
            <div className="max-w-2xl text-center">
                <p className="text-xs font-bold uppercase tracking-[0.25em] text-coral">Catalogue error 404</p>
                <p className="mt-5 font-serif text-[8rem] leading-[0.8] tracking-[-0.08em] text-cobalt sm:text-[11rem]">404</p>
                <h1 className="mt-8 font-serif text-4xl font-bold">This record is not on the shelf.</h1>
                <p className="mt-3 text-sm text-fog">The address may be out of date, but the rest of your collection is right where you left it.</p>
                <Button asChild className="mt-8"><Link to="/"><ArrowLeft className="size-4" />Return home</Link></Button>
            </div>
        </div>
    );
}
