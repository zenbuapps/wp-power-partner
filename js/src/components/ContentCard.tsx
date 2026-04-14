import type { ReactNode } from 'react'

interface ContentCardProps {
	children: ReactNode
	className?: string
}

const ContentCard = ({ children, className = '' }: ContentCardProps) => {
	return (
		<div
			className={`bg-white rounded-xl border border-gray-300/50 p-4 ${className}`}
		>
			{children}
		</div>
	)
}

export default ContentCard
