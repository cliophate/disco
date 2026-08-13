import type { MetadataEntityCoverage } from '../lib/types';
import { titleCase } from '../lib/utils';
import { Link } from 'react-router-dom';

export function MetadataCard({ entity, index }: { entity: MetadataEntityCoverage; index: number }) {
    const categories = (['identity', 'enrichment', 'artwork', 'narrative'] as const)
        .filter((category) => Object.keys(entity.statuses[category]).length > 0);

    return (
        <article className="border border-line bg-panel p-6 sm:p-8">
            <div className="flex items-start justify-between">
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-coral">File 0{index + 1}</p>
                <p className="font-serif text-5xl leading-none text-cobalt">{entity.identity_percentage}%</p>
            </div>
            <h2 className="mt-8 font-serif text-3xl font-bold">{titleCase(entity.type)}s</h2>
            <div className="mt-5 h-2 overflow-hidden rounded-full bg-raised" aria-label={`${entity.identity_percentage}% identified`}>
                <div className="h-full rounded-full bg-cobalt" style={{ width: `${Math.min(100, entity.identity_percentage)}%` }} />
            </div>
            <p className="mt-5 border-t border-line pt-5 text-sm text-fog">{entity.total.toLocaleString()} active library records</p>
            <div className="mt-5 space-y-5">
                {categories.map((category) => (
                    <section key={category}>
                        <h3 className="text-xs font-bold uppercase tracking-[0.16em] text-fog">{titleCase(category)}</h3>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {Object.entries(entity.statuses[category]).map(([status, count]) => (
                                <Link
                                    key={status}
                                    className="border border-line bg-paper px-2.5 py-1.5 text-xs font-semibold text-ink transition hover:border-cobalt hover:text-cobalt"
                                    to={`/metadata?type=${entity.type}&category=${category}&status=${status}&page=1`}
                                >
                                    {titleCase(status)} <span className="ml-1 tabular-nums text-fog">{count?.toLocaleString()}</span>
                                </Link>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </article>
    );
}
