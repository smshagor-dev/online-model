import ResetPassword from "@/components/reset-password";
export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const { token = "", email = "" } = await searchParams;
  return <ResetPassword token={token} email={email} />;
}
